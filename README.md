# webtrees_api

A small, token-authenticated **JSON API** module for [webtrees](https://webtrees.net) 2.x,
used to manage users and individuals on a family tree programmatically. webtrees has no
native write API; this wraps webtrees' own internal services (`UserService`, `Tree::createIndividual`
/ `createFamily`, `PendingChangesService`) so the search indexes and change-log stay consistent.

> ⚠️ **No secrets in this repo.** The API token and the "run as" user id are stored in the
> webtrees module preferences (database), not in code.

## Install
1. Copy this folder to `modules_v4/api/` on the webtrees server (deploy disabled-first as
   `api.disabled`, `php -l`, then rename to enable).
2. Set the preferences in the database (`wt_module_setting`, `module_name` = `_api_`):
   - `api_token` — a long random secret (required; no token ⇒ all requests get 403).
   - `run_as_user_id` — a manager/admin user id, so the API can read/write a private tree.

## Endpoint
`GET`/`POST` `/abf-api` — params via query string, JSON body, or form. Auth via `token`
param or `X-Api-Token` header. Responses are JSON (`{"ok":true,...}` / `{"ok":false,"error":...}`).
Optional `tree` param (defaults to the configured tree).

### Operations (`op=`)
| op | params | does |
|---|---|---|
| `ping` | — | health check; echoes tree + acting user |
| `user.lookup` | `user_id` \| `email` \| `user_name` | return a user + their tree link/role |
| `user.activate` | `user_id`, `xref`, `role`=edit | verify + admin-approve + link individual + set role |
| `user.create` | `user_name`, `real_name`, `email`, `password?` | create a user |
| `user.delete` | `user_id` | delete a user (refuses site admins) |
| `individual.get` | `xref` | return an individual |
| `individual.create` | `given_name`, `surname`, `sex`=M/F/U, `birth_date?`, `birth_place?` | create an individual |
| `individual.addSpouse` | `xref` (existing) + (`spouse_xref` \| `given_name`,`surname`,`sex`) | create/link a spouse, build the family, link both ways |
| `individual.addChild` | `parent_xref` + `given_name`,`surname`,`sex` (+ `family_xref?`) | add a child (to the parent's couple-family, a named family, or a new one) |
| `individual.update` | `xref` + any of `new_given`,`new_surname`,`sex`,`birth_date`,`birth_place`,`death_date`,`death_place`,`occupation`,`note` | rename + add/replace facts |
| `individual.delete` | `xref` | delete a family-less individual (test cleanup) |
| `user.update` | `user_id` + any of `real_name`,`email`,`user_name`,`password`,`role`,`verified`,`verified_by_admin`,`gedcomid` | change user fields |
| `user.list` | `filter`=all\|unverified\|unlinked, `limit?` | list users |

### Example
```bash
curl -s "https://<host>/index.php?route=/abf-api&op=ping&token=$TOKEN"
curl -s "https://<host>/index.php?route=/abf-api" \
  -H "X-Api-Token: $TOKEN" -H 'content-type: application/json' \
  -d '{"op":"individual.addSpouse","xref":"I353","given_name":"Vincenz","surname":"Waldstein-Wartenberg","sex":"M"}'
```

## Notes
- All writes run as the configured `run_as_user_id` and are force-accepted via
  `PendingChangesService`, so they go live immediately (no pending-change queue).
- Name/place/date inputs are sanitised to prevent GEDCOM line-structure injection.
- `individual.delete` only removes individuals with no family links (a safety guard).
