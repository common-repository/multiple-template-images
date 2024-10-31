=== Multiple Template Images ===
Contributors: basvd, reinaris
Donate link: http://reinaris.nl/wp/multiple-template-images
Tags: images, multiple, yapb, cut, crop, gd, template, posts, post, page
Requires at least: 3.0.1
Tested up to: 3.0.1
Stable tag: 1.1

Multiple Template Images allows you to add multiple image selection boxes to the post and page editor. These images can be displayed in your theme.

== Description ==

Multiple Template Images allows you to add multiple image selection boxes to the post and page editor. These images can be requested in your theme. You can cut, crop, blur, mask (etc.) these images using comprehensive functions (based on PHP's GD library).

Multiple Template Images is a good alternative for Wordpress template designers/developers who used to use YAPB to add a single image to a post. In contrast to the photoblog, this plugin allows you to add multiple images to a post in addition to providing the advanced image transformations.

This plugin is useful for those who develop templates for others (clients, users, editors) who have little technical knowledge of WordPress. You can easily let your client choose his own images, for example: headers, footers, images with effects (mask, blur, crop, etc.) and more. The user chooses the image, but you can control the page layout!

1. Define as many image uploads selectors as you like in your theme's `functions.php`. Please look at the "installation" tab for some examples. For even more examples, take a look at <http://www.reinaris.nl/wp/multiple-template-images>.
2. Let your client select the images he would like to use on a specific post or page. No more ugly page layouts because of users who wreck the layout in the visual editor. Completely control the position, width, heigth and effects of the images.

== Installation ==

Note: this plugin is for template developers only. Without any knowledge of WordPress themes, this is not easy to install. 

Step 1. Install and activate the plugin. Nothing will happen until you finish step 2.

Step 2. Open your `functions.php`, define your image slots in here:

<pre><code>
  if(class_exists('TemplateImages')) {
    mti_add_image_slot('first-image', 'Name for the first image');
    mti_add_image_slot('second-image', 'Name for the second image');
  }
</code></pre>

Step 3. Your post/page will have two image upload fields named "Name for the first Image" and "Name for the second image". Of course you're able to rename the image title, but choose the identifier carefully; renaming it while it has images attached will make it difficult to track those later on. Please do not use any fancy characters or spaces in the identifier (first-image).

Step 4. Now set a position in your template where these images will appear. The following code will echo the URL of the image, so you have to provide an <img> tag (or something else) yourself. For example: the URL for the first image slot shown in step 2 (if it exists):

<pre><code>
  if(class_exists('TemplateImages') && mti_image_exists('first-image')) {
    echo mti_get_image_url('first-image);
  }
</code></pre>

Step 5. Repeat step 4 for every image you defined in `functions.php` of your theme. Of course there are many ways to tweak your image. For some examples read the documentation at <http://www.reinaris.nl/wp/multiple-template-images>.

== Screenshots ==

1. Upload template images.