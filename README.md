# Grav Better comments Plugin

The **Better comments Plugin** for [Grav](http://github.com/getgrav/grav) adds the ability to add, manage and answer comments on Grav CMS, it my own version of **Comments Plugin** from this [link](https://github.com/getgrav/grav-plugin-comments)

# Installation

Clone from GitHub and put in the `user/plugins/bettercomments` folder.

# Usage

Add `{% include 'partials/bettercomments.html.twig' with {'page': page} %}` to the template file where you want to add comments.

For example, in Antimatter, in `templates/item.html.twig`:

```twig
{% embed 'partials/base.html.twig' %}

    {% block content %}
        {% if config.plugins.breadcrumbs.enabled %}
            {% include 'partials/breadcrumbs.html.twig' %}
        {% endif %}

        <div class="blog-content-item grid pure-g-r">
            <div id="item" class="block pure-u-2-3">
                {% include 'partials/blog_item.html.twig' with {'blog':page.parent, 'truncate':false} %}
            </div>
            <div id="sidebar" class="block size-1-3 pure-u-1-3">
                {% include 'partials/sidebar.html.twig' with {'blog':page.parent} %}
            </div>
        </div>

        {% include 'partials/bettercomments.html.twig' %}
    {% endblock %}

{% endembed %}
```

The comment form will appear on the blog post items matching the enabled routes.

To set the enabled routes, create a `user/config/plugins/comments.yaml` file, copy in it the contents of `user/plugins/comments/comments.yaml` and edit the `enable_on_routes` and `disable_on_routes` options according to your needs.

> Make sure you configured the "Email from" and "Email to" email addresses in the Email plugin with your email address!

# Enabling Recaptcha

The plugin comes with Recaptcha integration. To make it work, create a `user/config/plugins/comments.yaml` file, copy in it the contents of `user/plugins/comments/comments.yaml` and uncomment the captcha form field and the captcha validation process.
Make sure you add your own Recaptcha `site` and `secret` keys too.

> Make sure you use recaptcha v2

# Where are the comments stored?

In the `user/data/comments` folder. They're organized by page route, so every page with a comment has a corresponding file. This enables a quick load of all the page comments.

# Visualize comments

When the plugin is installed and enabled, the `Comments` menu will appear in the Admin Plugin. From there you can see all the comments made in the last 7 days, load more, accept, decline, delete or answer to users.

Feel free to change all you want.

# Email notifications

The plugin interacts with the Email plugin to send emails upon receiving a comment. Configure the Email plugin correctly, setting its "Email from" and "Email to" email addresses.

# Things still missing

- See deleted comments and real delete from comment file
- Cache clear from admin panel for comments not working
- Translatios to some other languages, only avaliable full translate for englis and spanish
- form-nomce not works properly

## Feel free to do whit you want whit this plugin
