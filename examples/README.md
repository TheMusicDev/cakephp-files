# Examples: an admin file browser

**These files are examples. They are not autoloaded and the plugin does not use them.** Copy them into your
application and adapt them: the plugin ships storage, the ledger and the serving route; the admin screens
(branding, auth, layout, CSS) belong to each site.

| File | Copy to | What it is |
|---|---|---|
| `src/Controller/FilesAdminController.php` | `src/Controller/` | list collections, list one collection's files (paginated), upload, soft-delete |
| `templates/FilesAdmin/index.php` | `templates/FilesAdmin/` | the one template for the list/folder view (unstyled markup, restyle it) |
| `routes.example.php` | merge into `config/routes.php` | four routes under `/admin/files` |

Things the example assumes, all yours to change:

- Your own `AppController` and a layout that renders `$this->Flash->render()`.
- An admin gate. The example has a `beforeFilter()` stub: put your auth check there. **The same check belongs in
  `Files.authCallable`**, otherwise the files are protected only in the browser screens and not at
  `/files/serve/{id}`.
- CakePHP's CSRF middleware (the forms send `_csrfToken`).
- The Paginator helper (`$this->Paginator->links()`); any pager works.
