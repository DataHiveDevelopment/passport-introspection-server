# Passport Introspection - Server

*THIS PACKAGE IS IN DEVELOPMENT!*
Further documentation and a proper composer package will be released.

## Overview

* Configure Passport
* Register introspection routes and migrations
  * `AuthServiceProvider.php`

```php
Passport::routes();
Introspection::routes();
```
  
* Define `getIntrospectionId()` method on User model
* Generate a `client credentials` grant for resource server (Used only for introspection)
  * Set the `can_introspect` database column to true, or override Laravel Passport's `Client` model and define a `canIntrospect()` method (Method takes priority over database)
* Generate an `authorization code` grant; You will need one for each resource server with a properly configured redirect URL for that server

## Real World

Contoso has a couple different services for their users:

* accounts.contoso.com - Users register, sign-in, and manage their accounts here. This is the `authorization server`, it issues API tokens.
* dirt.contoso.com - A new service that delivers dirt on request to users. This is a `resource server`.
* community.contoso.com - Contoso's official custom public forum for users to talk about and request features for Contoso's amazing line of applications. It is also a `resource server`.
* contosostore.test - Contoso's store, it handles user subscriptions. It is also a `resource server`.

All of these services have their own API, but Contoso doesn't want developers (both internally and externally) to need to request an OAuth client for each one they may want to use.

For example, a Contoso engineer may want to get details about the user from their *Accounts* platform while on the *Dirt* service. However, they also need to fetch subscription details from the *contosostore*. In a traditional setup, the engineer would need to setup an OAuth client on both the *Accounts* and *Contoso Store* API services. Then on *Dirt*, they would need to manage the associated access token, expiration and refresh tokens for each user on both services. Not to mention making sure they use the right token when making API calls to each platform.

With introspection, they can request one access token from the authorization server (the *Accounts* platform) from any resource server and make API calls to any other service as the user (as long as they have the right scopes and permissions on the token). No more worrying about maintaining access tokens across multiple systems!

## Real World - Technical

Continuing with our example from above, let's dive into the technical details of how this would work.

You maintain one source of truth for your users across your platform, on your authorization server (the *Accounts* platform). Using the magic and power of OAuth, you implement a sort of 'Log in with...' style login on each of your resource servers.

To do this, your users will need a unique ID that you can use to associate the user across services. You can't use the auto-increment ID that the User model has so I recommend adding something like a `uuid` column to the User model on all platforms. On your resource servers, you only need to really know the users UUID.

The introspection server package will, with the help of the `getIntrospectionId()` method on the User model, return this attributes value in the introspection request to your resource servers as the User ID. The resource server will use the UUID returned to find the matching user in it's local database with the help of the `findForIntrospection()` method on the User model. This allows you to know who to act on in an API request just like if you were using Passport for an API on a single server.

The Introspection Client package provides nearly all the same functionality that Passport does:

* Allows use of the `Auth` facade/global helper on API calls
* Use scopes to restrict access to API routes
* Access the API backend from a Javascript frontend with Axios without needing to request an access token
