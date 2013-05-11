## MailChimp Subscribe Profile:Edit Edition

### Requirements

**Profile:Edit v1.0.9** or greater.

**ExpressionEngine 2.5.3** or greater.

This version of Mailchimp Subscribe only works with Profile:Edit installed. This version of MailChimp Subscribe uses channel fields instead of member fields. Supports member registration, editing a profile, and members self validating via email.

Originally forked from [Stephen Lewis's MailChimp Subscribe][fork_src]

Big Thanks to Stephen Lewis

## Profile:Edit Hooks

* [profile\_register\_end][pe_hook1]
* [profile\_edit\_end][pe_hook2]

## EE Hooks

* [member\_register\_validate\_members][ee_hook1]

## Wiki

Check out the wiki for [installation and usage instructions][wiki]

[wiki]: https://github.com/experience/mailchimp_subscribe.ee_addon/wiki/ "View the documentation"
[fork_src]: https://github.com/experience/mailchimp_subscribe.ee_addon/ "Stephen Lewis's MailChimp Subscribe"
[pe_hook1]: http://mightybigrobot.com/docs/profile-edit/#profile_register_end "profile_register_end"
[pe_hook2]: http://mightybigrobot.com/docs/profile-edit/#profile_edit_end "profile_edit_end"
[ee_hook1]: http://ellislab.com/expressionengine/user-guide/development/extension_hooks/module/member_register/#id4 "member_register_validate_members"

## Overview

Automatically add new members who register via Profile:Edit addon to MailChimp mailing lists.
