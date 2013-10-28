Static site generator
=====================

This plugin generates a static snapshot of a WordPress site, and updates it
as needed. The resulting static tree can be served independently of WordPress
itself (and in fact the web server responsible doesn't need to be able to
execute PHP or CGI scripts).

The plugin was developed for and used to serve [The Space](http://thespace.org/).

-- Mo McRoberts <mo.mcroberts@bbc.co.uk>

Installation instructions
-------------------------

1. Check out this and the [freedom-ain](https://github.com/bbcarchdev/freedom-ain) plugins into your `wp-content/plugins` directory.
2. Create a directory to hold the static tree, and ensure it's writeable by the web server user.
3. Make sure that you have a 'friendly' permalink structure defined and that it's working properly (you may need to install a `.htaccess` file or equivalent - see the Settings > Permalinks section in WordPress): if you use URL parameters to access posts or pages (e.g., `/?p=5`), then this plugin can't run.
4. In your `wp-config.php`, define `STATICGEN_PATH` to the full path of the directory that you created above, and `STATICGEN_PUBLIC_URL` to the URL that you'll be using to serve it from (including trailing slash). At least initially, you should also define `STATICGEN_REBUILD_ON_SAVE` to true.
5. Activate both plugins.
6. Check Settings > Permalinks to verify that the plugin has been activated and is working okay.
7. Edit a post (you don't need to modify anything) and press 'Update'.
8. Confirm that the directory that you created is no longer empty. Check your web server's error log if you don't see a symbolic link named `current` within it.
9. Configure your web server to serve from that `current` symbolic link: it will be updated to point to the latest complete tree automatically. You may need to explicitly configure your server to follow symbolic links.

Runtime configuration options
-----------------------------

There are various tuneable parameters which you can define in `wp-config.php`; these are displayed on the Settings > Permalinks page and described near the top of [static.php](https://github.com/bbcarchdev/static/blob/master/static.php). In summary:

* `STATICGEN_INSTANCE`: by default defined to the current hostname; may be overridden if your hostname is in the habit of changing (it's used for locking in case you have multiple copies of WordPress running in a replicated setup).
* `STATICGEN_ENV`: if defined to `live`, debugging will be disabled by default.
* `STATICGEN_DEBUG`: enables or disables debug-logging to your web server's error log; enabled by default unless `STATICGEN_ENV` is set to `live`.
* `STATICGEN_INHIBIT_CRON_REBUILD`: if defined to true, prevents `wp-cron` actions from being scheduled; if you define this, you must arrange for [cron.php](https://github.com/bbcarchdev/static/blob/master/cron.php) to be invoked as the web server user on a periodic basis.
* `STATICGEN_REBUILD_ON_SAVE`: if defined to true, the rebuild process will happen immediately when you press the 'Update' button in the post editor; if defined to false, a periodic action will be scheduled to be invoked via `wp-cron`. Define to false if rebuilding starts to take a long time.
* `STATICGEN_USE_VAR`: if defined to true, generate a var-map file for use with Apache. The process by which this is written is somewhat brute-force (the plugin simply writes a var-map containing all registered MIME types).
* `STATICGEN_VAR_IN_PARENT`: if defined to true, place the generated var-map file in the parent directory (except in the case of the root) with the same name as the subdirectory (e.g., `sample-page.var` will refer to `sample-page/index.html`); if defined to false (the default), the var-map file will be written into the subdirectory itself and named `index.var`.
* `STATICGEN_USE_ASIS`: if defined to true, all resources will be written as `.asis` files for use with Apache and contain the relevant HTTP response headers as well as the response payload; if defined to false (the default), resources will be written as ordinary files.
* `STATICGEN_INHIBIT_FETCH`: if defined to true, no resources will be written which rely on fetching a generated page from your WordPress instance itself. This option is generally only useful for debugging and profiling.
