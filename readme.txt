=== Plugin Name ===
Contributors: kyon79, wordpressdotorg, asenar
Donate link: 
Tags: importer, dotclear
Requires at least: 3.5
Tested up to: 3.5
Stable tag: ?

Import categories, users, posts, comments, and links from a DotClear blog.

== Description ==
Based on http://plugins.svn.wordpress.org/dotclear2-importer/ 

modified to fit my needs of dotclear2.2 multiblog import


Import categories, users, posts, comments, and links from a DotClear blog.

== Installation ==

1. Upload the `dotclear2-importer` folder to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. Go to the Tools -> Import screen, Click on DotClear2

== Changelog ==
= 0.4 =
* Forked from dotclear-importer2
* Added field "blog_id" to allow importation from one blog only
* replaced deprecated function getuserdatabylogin($login) by get_user_by('login', $login)
* removed some php warnings / notices
* option to import only active post
* option to skip comments import
* option to skip links import

= 0.3 =
* Forked from dotclear-importer
* Changed Dotclear table names (initially in French, not in dc2 anymore)
* Added Parent/Children Category management

= 0.1 =
* Initial release
