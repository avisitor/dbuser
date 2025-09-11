# retree/dbuser

DBUser DAO extracted from retreehawaii.

Depends on `retree/dbbase`.

Setup schema:
- Apply `sql/init.sql` to create the minimal `users` and `tags` tables used by DBUser.

Install (local path):

```json
{
  "require": { "retree/dbuser": "*@dev" },
  "repositories": [
    { "type": "path", "url": "../php-libs/dbbase", "options": { "symlink": true } },
    { "type": "path", "url": "../php-libs/dbuser", "options": { "symlink": true } }
  ]
}
```

Usage:

```php
use Retree\DB\DBConnection;
use Retree\DB\DBBase;
use Retree\User\DBUser;

$pdo = DBConnection::fromEnv();
DBBase::setDefaultConnection($pdo);

$u = new DBUser();
$user = $u->getByEmail('someone@example.com');
```
