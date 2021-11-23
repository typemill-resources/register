# Register Plugin

The plugin adds a public registration form with double opt in to your Typemill Website (/tm/register). You can optionally activate a gumroad licence for selling access. It requires the email plugin to work. Written by [trendschau.net](https://trendschau.net) (Sebastian Schürmanns) and [Andreas Zettl](https://azettl.net).

## How it works 

Before you install the register plugin, please install and configure the [mail plugin](https://plugins.typemill.net/mail). 

To install the register plugin, simply download and unzip the plugin-folder, then upload the files to the plugin folder of your Typemill installation and fill out the forms in the plugin settings. After that you can reach the public registration form with the path /tm/register.

![Screenshot of the register form](media/live/register-1.png){loading="lazy" .center}

## Registration Features

* Creates a register page under /tm/register
* Define the registration form with YAML.
* Add a field for a gumroad licence key if you want to sell the access.
* Editable welcome page.
* Confirmation Email with editable content.
* Page to request the confirmation mail again.
* Automatically remind the user to confirm the account after X days.
* Automatically delete unconfirmed user after Y days.
* Define the role for registered users (member by default).

## Security Features

* Double opt in. User has no access to his account without confirmation.
* Standard CSRF-Protection
* Standard honeypot field for simple spam protection.
* Optionally activate a captcha field (build-in captcha).
* Optionally activate a google recaptcha field.
* Backend input validation.
* Check for existing usernames and emails.
* Check against burner-mails

## Public Registration Form

You can define an individual public registration form in the plugin settings with YAML.

Never heared about YAML? Don't worry! It is super simple and you do not have to code anything. Just copy and paste some fields from the [documentation](https://typemill.net/forms/field-overview) and change the definitions like you want. 

Do you need some examples?

Then let us look at the definition of the standard forms. If you delete all definitions The YAML for the four standard-fields "username", "email", "password", and "gumroad" will appear. The definitions for them looks like this: 

```
username:
  type: text
  label: username
  placeholder: Username
  required: true

email:
  type: text
  label: E-Mail-New
  placeholder: Email
  required: true

password:
  type: password
  label: Password
  required: true

gumroad:
  type: password
  label: Gumroad Licence Key
  required: true 
```

! DO NOT DELETE OR RENAME THE FIELDS "username", "email" or "password". They are required for the functionality of the register plugin.

**Question**: OK, looks nice, but I want to translate the labels for each field.  
**Answer**: No problem, just change the text for the label, for example from "label: username" to "label: Nutzername".  
**Question**: But I don't need the field for gumroad.  
**Answer**: No problem again, simply delete it from the YAML definition!  
**Question**: But I need a checkbox for the user so he can agree to my terms and conditions.  
**Answer**: And again no problem, you can simply define it like this:

```
username:
  type: text
  label: username
  placeholder: Username
  required: true

email:
  type: text
  label: E-Mail-New
  placeholder: Email
  required: true

password:
  type: password
  label: Password
  required: true

legal:
  type: checkbox
  label: Terms and conditions
  checkboxlabel: I accept the [Terms and conditions](https://yoursite.com/terms) of this website
  required: true
```

**Question**: Ahh, that is easy. Can I also add another legal hint without a checkbox? Just Text?  
**Answer**: Yes...

```
username:
  type: text
  label: username
  placeholder: Username
  required: true

email:
  type: text
  label: E-Mail-New
  placeholder: Email
  required: true

password:
  type: password
  label: Password
  required: true

legal:
  type: checkbox
  label: Terms and conditions
  checkboxlabel: I accept the [Terms and conditions](https://yoursite.com/terms) of this website
  required: true

hint:
  type: paragraph
  value: With your registration, you automatically accept our [Terms and conditions](https://yoursite.com/terms) 
```

That is the whole magic, and yes, this is flexible as hell! Again, just check the [documentation](https://typemill.net/forms/field-overview) and create your own special registration form if you want.


## Updates

### Version 1.3.0

* Switch to publicformdefinitions so the admin can define his own forms.
* Switch to new public forms logic with route and validation.
* Switch to new security-logic with optional build-in-captcha and google recaptcha.
* Add a gumroad licence check.
* Check burner email accounts.
* Send email domain to admin so he can check spam better.
* Create a tmp_user in session so other plugins (e.g. subscription) know the username even if user is not logged in yet.
* Deleted recaptcha from honeypot site.
* Fixed logic for registersteps, so you can hack before and after each step now.
* Fixed progress bar for welcome page

### Version 1.2.0

* Choose the role for new registered users (requires Typemill Version 1.4.5)

### Version 1.1.0

* Added an option to notify the admin when a new user has registered.
* Added an option to notify the admin when the user has confirmed his account.
* Removed the _ from the username in the login form after successful optin.