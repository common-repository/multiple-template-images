<?php
/*
Plugin Name: Multiple Template Images
Plugin URI: http://www.reinaris.nl/wp/multiple-template-images
Description: Gives users the ability to add imagery to posts and pages. And provides template features for developers to use and transform these pictures in their themes.
Version: 1.0
Author: Bas van Doren
Author URI: http://sparepencil.com/
License: GPL3

Copyright 2010 Bas van Doren

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

if (!class_exists('TemplateImages')) :
  class TemplateImages
  {
    // Instance variables
    var $image_slots = array();
    var $notices = array();

    // Class constants
    var $name = 'multiple-template-images';
    var $meta_name = '_template-images';
    var $mimes = array('image/gif', 'image/jpeg', 'image/jpg', 'image/png');

    // Extracted from phpthumb.class.php
    var $thumb_args = array(
      'w' => null,     // Width
      'h' => null,     // Height
      'wp' => null,     // Width  (Portrait Images Only)
      'hp' => null,     // Height (Portrait Images Only)
      'wl' => null,     // Width  (Landscape Images Only)
      'hl' => null,     // Height (Landscape Images Only)
      'ws' => null,     // Width  (Square Images Only)
      'hs' => null,     // Height (Square Images Only)
      'f' => null,     // output image Format
      'q' => 75,       // jpeg output Quality
      'sx' => null,     // Source crop top-left X position
      'sy' => null,     // Source crop top-left Y position
      'sw' => null,     // Source crop Width
      'sh' => null,     // Source crop Height
      'zc' => null,     // Zoom Crop
      'bc' => null,     // Border Color
      'bg' => null,     // BackGround color
      // fltr[]=...&fltr[]=...
      'fltr' => array(),  // FiLTeRs
      'err' => null,     // default ERRor image filename
      'xto' => null,     // extract eXif Thumbnail Only
      'ra' => null,     // Rotate by Angle
      'ar' => null,     // Auto Rotate
      'aoe' => null,     // Allow Output Enlargement
      'far' => null,     // Fixed Aspect Ratio
      'iar' => null,     // Ignore Aspect Ratio
      'maxb' => null,     // MAXimum Bytes
      'sfn' => 0,        // Source Frame Number
      'dpi' => 150      // Dots Per Inch for vector source formats
    );

    function TemplateImages()
    {
      $this->wp_upload_dir = wp_upload_dir();

      // Enable meta box
      add_action('admin_menu', array(&$this, '_on_admin_menu'));
      add_action('admin_notices', array(&$this, '_on_admin_notices'));
      add_action('admin_print_styles', array(&$this, '_on_admin_styles'));
      add_action('post_edit_form_tag' , array(&$this, '_on_post_form_tag'));

      // Post change actions
      add_action('save_post', array(&$this, '_on_post_change'));
      add_action('delete_post', array(&$this, '_on_post_delete'));
    }

    function __construct()
    {
      self::TemplateImages();
    }

    /*
     * Notes:
     * - Original images are stored by WordPress in default upload location (uploads/<date-subdirs>/<name>.<ext>)
     * - When uploading/replacing an image, the old one must be removed first
     * - Cached images are stored by the plugin (uploads/<plugin>/<post_uniqid>/<name>.<hash>.<ext>
     *
     * Image slots spec.:
     * - see: add_image_slot() function
     *
     * Custom field spec.:
     * <meta_name> : Array
     * (
     *   'post_dir' => <dir_name>, // The location for cached files
     *   'images' => Array
     *   (
     *     <img_id> => // The name of the image slot
     *       <img_file>, etc. // Location relative to wp-content/uploads (eg. 2010/09/image.gif)
     *   )
     * )
     */

    /*
     * Action handlers
     */

    function _on_admin_menu()
    {
      if(function_exists('add_meta_box') && !empty($this->image_slots)) // The function check seems unnecessary (add_meta_box() exists in all recent versions)
      {
        add_meta_box($this->name, __('Template Images', $this->name), array(&$this, 'show_meta_box'), 'post', 'normal', 'high');
        add_meta_box($this->name, __('Template Images', $this->name), array(&$this, 'show_meta_box'), 'page', 'normal', 'high');
      }
    }

    function _on_admin_styles()
    {
?>
<style>
/* <![CDATA[ */
.<?php echo $this->name; ?>-thumb {
  background-color: #ffffff;
  border: 1px solid #aaaaaa;
  width: 150px;
  height: 100px;
  overflow: hidden;
  float: left;
  padding: 5px;
  margin: 0 5px;
  text-align: center;
}
.<?php echo $this->name; ?>-box {
  float: left;
  width: 50%;
  min-width: 460px;
}
/* ]]> */
</style>
<?php
    }

    function _on_post_form_tag()
    {
      echo ' enctype="multipart/form-data"';
    }

    function _on_post_change($post_id)
    {
      // Avoid unsaved posts, revisions and autosaves
      if(wp_is_post_autosave($post_id) || wp_is_post_revision($post_id) || get_post_status($post_id) == 'auto-draft') return;

      $clear_cache = false; // Determines whether post cache needs to be cleared after update

      // Handle new uploads
      if(!empty($this->image_slots)) foreach($this->image_slots as $img_id => $slot)
      //foreach($_FILES as $k => $file)
      {
        $file_id = $this->name.'_'.$img_id;

        // If the image has to be deleted, it must be done first
        if(isset($_POST[$file_id.'_delete']) && 'on' == $_POST[$file_id.'_delete'])
          $this->delete_image($post_id, $img_id);

        // Only proceed if the file exists in the form
        if(!isset($_FILES[$file_id])) continue;
        $file = $_FILES[$file_id];

        $img_info = $this->image_slots[$img_id];
        $info = wp_handle_upload($file, array('action' => $_POST['action']));

        // Verify upload
        if(isset($info['error']))
        { // Notify, then skip the file (no file skips quietly)
          if($file['error'] != UPLOAD_ERR_NO_FILE)
            $this->notices[] = sprintf(__('Error while uploading <strong>%s</strong>. <em>%s</em>', $this->name), $img_info['title'], $info['error']);
          continue;
        }
        // Verify file
        if(!in_array($info['type'], $this->mimes))
        { // Notify, then delete and skip the file
          $this->notices[] = sprintf(__('Error while uploading <strong>%s</strong>. <em>The file is not a GIF, JPEG or PNG image.</em>', $this->name), $img_info['title']);
          unlink($info['file']);
          continue;
        }

        // Make sure all slashes are pointing in the same direction
        $upload_base = str_replace('\\', '/', $this->get_upload_dir(false, true));
        $info['file'] = str_replace('\\', '/', $info['file']);
        // Extract relative part of file location (<full_file_path> minus <uploads_base_path>)
        if(0 == strpos($upload_base, $info['file'])) // In theory this is always true, but it never hurts to check
        {
          $location = trim(substr($info['file'], strlen($upload_base)), '/'); // No slashes at the ends
          $this->set_image($post_id, $img_id, $location);
          $clear_cache = true;
          $this->notices[] = sprintf(__('<strong>%s</strong> uploaded successfully!', $this->name), $img_info['title']);
        }
        else
        { // Notify, then delete and skip the file
          unlink($info['file']);
          $this->notices[] = sprintf(__('Error while uploading <strong>%s</strong>. <em>The file was saved to an unexpected location and has been removed.</em>', $this->name), $img_info['title']);
          continue;
        }
      }
      if($clear_cache) $this->clear_post_cache($post_id);

      // Redirect happens directly after saving, so notify later
      $this->_keep_transient_notices();
    }

    function _on_post_delete($post_id)
    {
      $this->delete_all_images($post_id);
    }

    // Store notices until they can be displayed
    function _keep_transient_notices($t = 5)
    {
      if(!empty($this->notices))
        set_transient($this->name.'_notices', $this->notices, $t);
      else
        delete_transient($this->name.'_notices');
    }

    // Show important messages
    function _on_admin_notices()
    {
      $notices = empty($this->notices) ? get_transient($this->name.'_notices') : $this->notices;
      if(!empty($notices) && $notices !== false) :
?>
<div class="updated fade">
<?php
        foreach($notices as $note) :
?>
  <p><?php echo $note; ?></p>
<?php
        endforeach;
?>
</div>
<?php
      endif;
    }

    /*
     * Developer API (template tags and customization functions)
     */

    // Register an image slot in the posts and page editors
    function add_image_slot($img_id, $title, $default = null)
    {
      $this->image_slots[$img_id] = array('title' => $title, 'default' => $default);
    }

    // Return the URL for an image, usually a cached image transformation of the original, or the original itself (if no transformation is done)
    function get_image_url($post_id, $img_id, $thumb_args = array())
    {
      if(!$this->image_exists($post_id, $img_id)) return '';

      if(empty($thumb_args))
      { // Nothing to transform, return the original
        return $this->get_upload_dir(true, true).'/'.$this->get_original_image($post_id, $img_id);
      }
      else
      {
        $cached_img = $this->get_cached_image($post_id, $img_id, $thumb_args);
        if(!empty($cached_img)) return $this->get_upload_dir(true, true).'/'.$cached_img;
      }
      return '';
    }

    function image_exists($post_id, $img_id, $ignore_default = false)
    {
      return strlen($this->get_original_image($post_id, $img_id, $ignore_default)) > 0;
    }

    /*
     * Data management
     */

    // Generate a hash to identify a cached image transformation
    function get_cache_id($thumb_args)
    {
      ksort($thumb_args);
      $arg_str = '';
      // Compile the arguments into a single string
      foreach($thumb_args as $k => $v)
      {
        if(is_array($v))
        {
          foreach($v as $x)
          {
            $arg_str = $arg_str.'&'.$k.'[]='.$x;
          }
        }
        else
        {
          $arg_str = $arg_str.'&'.$k.'='.$v;
        }
      }
      return md5($arg_str);
    }

    // Attempt to retrieve a cached image location (relative to uploads basedir), or create it when cache misses
    function get_cached_image($post_id, $img_id, $thumb_args)
    {
      if(!$this->image_exists($post_id, $img_id)) return '';

      $thumb_args = wp_parse_args($thumb_args, $this->thumb_args);
      $cache_id = $this->get_cache_id($thumb_args);
      $post_dir = $this->get_post_dir($post_id);
      $file = sprintf('%s/%s.%s', $post_dir, $img_id, $cache_id);

      $pattern = sprintf('%s/%s.*', $this->get_upload_dir(), $file);
      $search = glob($pattern);
      if(is_array($search) && !empty($search))
      {
        return sprintf('%s/%s/%s', $this->name, $post_dir, basename($search[0]));
      }
      else
      {
        require_once(dirname(__FILE__).'/phpthumb/phpthumb.class.php');
        $php_thumb = new phpThumb();
        $php_thumb->setSourceFilename($this->get_upload_dir(false, true).'/'.$this->get_original_image($post_id, $img_id));
        foreach($thumb_args as $k => $v)
        {
          $php_thumb->setParameter($k, $v);
        }
        $out_file = $this->get_upload_dir().'/'.$file;
        if($php_thumb->GenerateThumbnail() && $php_thumb->RenderToFile($out_file))
        {
          $ext = $php_thumb->config_output_format;
          rename($out_file, $out_file.'.'.$ext);
          
          // Set correct file permissions
          $stat = stat($out_file.'.'.$ext);
          $mode = $stat['mode'] & 0000644;
          @chmod($out_file.'.'.$ext, $mode);

          return sprintf('%s/%s.%s', $this->name, $file, $ext);
        }
      }
      return '';
    }

    // Original image location (relative to uploads basedir)
    function get_original_image($post_id, $img_id, $ignore_default = false)
    {
      $meta = get_post_meta($post_id, $this->meta_name, true);
      if(!empty($meta) && isset($meta['images'][$img_id]))
      {
        return $meta['images'][$img_id];
      }
      elseif(!$ignore_default && isset($this->image_slots[$img_id]) && !empty($this->image_slots[$img_id]['default']))
      {
        // Defaults must exist inside $this->upload_dir
        $default = $this->image_slots[$img_id]['default'];
        if(file_exists($this->get_upload_dir().'/'.$default))
          return $this->name.'/'.$default;
      }
      return '';
    }

    // Retrieves the directory for a post or creates a unique one if it doesn't already exist
    function get_post_dir($post_id = null)
    {
      if($post_id != null)
      {
        $meta = get_post_meta($post_id, $this->meta_name, true);
        if(empty($meta)) return null;
        $dir = $meta['post_dir'];
      }
      else
      {
        do
        {
          $dir = uniqid();
        }
        while(is_dir($this->get_upload_dir().'/'.$dir));
      }
      wp_mkdir_p($this->get_upload_dir().'/'.$dir);
      return $dir;
    }

    function get_upload_dir($url = false, $wp = false)
    {
      return ($url ? $this->wp_upload_dir['baseurl'] : $this->wp_upload_dir['basedir']).($wp ? '' : '/'.$this->name);
    }

    // Set/update post metadata and, if necessary, remove the previous image
    function set_image($post_id, $img_id, $location)
    {
      $meta = get_post_meta($post_id, $this->meta_name, true);
      if(empty($meta))
      {
        $meta = array(
          'post_dir' => $this->get_post_dir(),
          'images' => array()
        );
      }
      // Try to delete old image (does nothing otherwise)
      $this->delete_image($post_id, $img_id, true);
      // Update/set metadata
      $meta['images'][$img_id] = $location;
      update_post_meta($post_id, $this->meta_name, $meta);
    }

    // Delete all the data associated with an image (original + cached files)
    function delete_image($post_id, $img_id, $skip_meta = false)
    {
      $meta = get_post_meta($post_id, $this->meta_name, true);
      if(isset($meta['images'][$img_id]))
      {
        unlink($this->get_upload_dir(false, true).'/'.$meta['images'][$img_id]);
        // Clear metadata (can be skipped in case of updates)
        if(!$skip_meta)
        {
          unset($meta['images'][$img_id]);
          update_post_meta($post_id, $this->meta_name, $meta);
        }
      }
    }

    // Delete all images associated with a post
    function delete_all_images($post_id, $skip_meta = false)
    {
      $meta = get_post_meta($post_id, $this->meta_name, true);
      // Delete uploads
      if(!empty($meta)) foreach($meta['images'] as  $k => $v)
      {
        unlink($this->get_upload_dir(false, true).'/'.$v);
      }
      if(!$skip_meta) delete_post_meta($post_id, $this->meta_name);
      // Delete cache + cache directory
      $this->clear_post_cache($post_id, false);
    }

    // Clears all cached files associated with a post
    function clear_post_cache($post_id, $keep_dir = true)
    {
      $this->_delete_post_files($post_id, '*', $keep_dir);
    }

    // Clear all cached files
    function clear_all_cache()
    {
      $pattern = sprintf('%s/%s', $this->get_upload_dir(), '*/*');
      $glob = glob($pattern);
      if(is_array($glob) && !empty($glob))
      {
        foreach($glob as $file)
          unlink($file);
      }
    }

    // Deletes files from a post dir based on pattern (also deletes directory if empty)
    function _delete_post_files($post_id, $pattern, $keep_dir = false)
    {
      $dir = $this->get_post_dir($post_id);
      if($dir == null) return;

      $base = sprintf('%s/%s', $this->get_upload_dir(), $dir);
      $glob = glob($base.'/'.$pattern);
      if(is_array($glob) && !empty($glob))
      {
        foreach($glob as $file)
          unlink($file);
      }
      if(!$keep_dir && count(glob($base.'/*')) < 1) rmdir($base);
    }

    /*
     * GUI parts
     */

    // Shows the upload form in the post/page editor
    function show_meta_box($post)
    {
      $no_img_url = plugins_url($this->name.'/image.png');
      if(!empty($this->image_slots)) foreach($this->image_slots as $k => $v) :
        $input_id = $this->name.'_'.$k;
        $img_url = $this->get_image_url($post->ID, $k, 'w=150&h=100');
?>
<div class="<?php echo $this->name; ?>-box">
  <h4><?php echo $v['title']; ?></h4>
  <div class="<?php echo $this->name; ?>-thumb">
    <img src="<?php echo (empty($img_url)) ? $no_img_url : $img_url; ?>" alt=""/>
  </div>
<?php
        if($this->image_exists($post->ID, $k, true)) :
?>
  <p>
    <input type="checkbox" name="<?php echo $input_id; ?>_delete" id="<?php echo $input_id; ?>_delete" value="on"/>
    <label for="<?php echo $input_id; ?>_delete"><?php _e('Remove this image.', $this->name); ?></label>
  </p>
<?php
        endif;
?>
  <p>
    <label for="<?php echo $input_id; ?>"><?php _e('Choose an image:', $this->name); ?></label><br/>
    <input type="file" name="<?php echo $input_id; ?>" id="<?php echo $input_id; ?>"/>
  </p>
  <br class="clear"/>
</div>
<?php
      endforeach;
?>
<br class="clear"/>
<?php
    }
  }
  $templateimages = new TemplateImages();
  /**
   * Registers an image slot.
   * Use this function in a plugin or your theme's functions.php file.
   *
   * @param string $img_id Unique name by which to identify the image
   * @param string $title Descriptive title to display in the editor
   * @param string $default Location (relative to uploads/picture-paste-up) of the default image (experimental feature!)
   */
  function mti_add_image_slot($img_id, $title, $default = null)
  {
    global $templateimages;
    return $templateimages->add_image_slot($img_id, $title, $default);
  }
  /**
   * Returns the URL of an image, which may have been transformed with phpThumb (image transformation).
   * Use this function inside the Loop only!
   *
   * @param string $img_id The image identifier
   * @param string|array $thumb_args phpThumb parameters in either query string or array format
   * @return string Full URL of the image or an empty string on failure
   */
  function mti_get_image_url($img_id, $thumb_args = array())
  {
    global $templateimages, $post;
    return $templateimages->get_image_url($post->ID, $img_id, $thumb_args);
  }
  /**
   * Checks whether an image exists for the given image slot.
   * Use this function inside the Loop only!
   *
   * @param string $img_id The image identifier
   * @return bool
   */
  function mti_image_exists($img_id)
  {
    global $templateimages, $post;
    return $templateimages->image_exists($post->ID, $img_id, true);
  }
endif;
