# webtrees_api

A small, **token-authenticated JSON API** for [webtrees](https://webtrees.net) 2.x that lets you
manage **users** and **individuals/families** programmatically — create and edit people, link
spouses / children / parents, add events, and administer user accounts.

webtrees has no built-in write API. This module wraps webtrees' **own internal services**
(`UserService`, `Tree::createIndividual`/`createFamily`, `GedcomRecord::createFact`/`updateFact`,
`PendingChangesService`) so that the name index (`wt_name`), link index (`wt_link`) and the
change-log all stay correct — exactly as if you'd edited through the web UI. Every change is
**force-accepted**, so it goes live immediately rather than sitting in the pending-changes queue.

> ⚠️ **Security:** there are **no secrets in this repository**. The API token and the "run as"
> user id are stored in the webtrees database (module settings), never in code. Treat the token
> like a password and only call the API over HTTPS.

---

## Requirements

- webtrees **2.1.x or 2.2.x** (tested on 2.1.x).
- Access to the webtrees server filesystem (to install the module) and to its database (to set
  the token). A MySQL/MariaDB client, or phpMyAdmin, is enough.

---

## 1. Install the module

webtrees loads **every enabled module on every request**, so a broken module file can take the
whole site down. Install **disabled-first** to stay safe:

1. Copy this folder into your webtrees `modules_v4/` directory, but name it with a dot so webtrees
   ignores it at first:

   ```
   modules_v4/api.disabled/
     ├── module.php
     ├── ApiModule.php
     └── README.md
   ```

   (Download the repo, or `git clone https://github.com/jbh4x82/webtrees_api modules_v4/api.disabled`.)

2. Lint the files on the server to be sure they parse:

   ```bash
   php -l modules_v4/api.disabled/module.php
   php -l modules_v4/api.disabled/ApiModule.php
   ```

3. Enable it by removing the dot (webtrees ignores any folder whose name contains `.`):

   ```bash
   mv modules_v4/api.disabled modules_v4/api
   ```

4. Open your site's home page. If it still loads, you're good. **Kill switch** if anything breaks:
   `mv modules_v4/api modules_v4/api.disabled` and the site recovers instantly.

The **module name** webtrees uses internally is `_` + folder name + `_`. So a folder called `api`
⇒ module name **`_api_`**. (Remember this for the settings below; if you rename the folder, the
module name changes.)

5. In webtrees, go to **Control panel → Modules** and make sure the module is enabled.

---

## 2. Configure the token (required)

The API refuses every request (HTTP 403) until you set a token. Settings live in the
`module_setting` table. **Use your own table prefix** (default `wt_`, see `tblpfx` in
`data/config.ini.php`) and the module name from step 1 (`_api_`).

Generate a long random token, e.g.:

```bash
openssl rand -hex 24
```

Then insert two settings:

```sql
-- replace wt_ with your table prefix, and paste your generated token
INSERT INTO wt_module_setting (module_name, setting_name, setting_value) VALUES
  ('_api_', 'api_token', 'PASTE_YOUR_LONG_RANDOM_TOKEN_HERE'),
  ('_api_', 'run_as_user_id', '1')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
```

- **`api_token`** — the shared secret. No token configured ⇒ all requests get `403 forbidden`.
- **`run_as_user_id`** — the webtrees **user id the API acts as**. Set this to a **tree manager
  or administrator** (find ids in **Control panel → Users**, or `SELECT user_id, user_name FROM
  wt_user`). This is essential for a **private tree**: the API runs unauthenticated, so without a
  privileged "run as" user, webtrees privacy would hide all data and block writes.
- Optional **`tree`** setting overrides which tree the API works on; otherwise it uses the first
  argument-less default (`meran.ged` in the bundled copy — change `DEFAULT_TREE` in `ApiModule.php`
  or pass `&tree=yourtree.ged` per request).

To retrieve the token later: `SELECT setting_value FROM wt_module_setting WHERE module_name='_api_'
AND setting_name='api_token';`

---

## 3. Call the API

- **Endpoint:** `https://YOUR_SITE/index.php?route=/abf-api` (or `https://YOUR_SITE/abf-api` if you
  have pretty-URLs enabled).
- **Method: GET.** (webtrees' CSRF protection blocks POST without a session token, so this API is
  GET-only.) Pass parameters as query-string values.
- **Auth:** add `token=YOUR_TOKEN` as a parameter, or send an `X-Api-Token: YOUR_TOKEN` header.
- **Responses:** JSON. Success → `{"ok": true, ...}`; failure → `{"ok": false, "error": "..."}`.
- **Tree:** optional `tree=NAME.ged` to target a specific tree.

Always URL-encode values (spaces, umlauts, etc.). With `curl`:

```bash
SITE="https://your.tree.example"; TOKEN="your-token"
api() { curl -s -G "$SITE/index.php" --data-urlencode "route=/abf-api" \
        --data-urlencode "token=$TOKEN" "$@"; }

api --data-urlencode "op=ping"
```

---

## 4. Operations

Every call needs `op=<operation>` plus the params below. `?` marks optional params.

### Read
| op | params | returns |
|---|---|---|
| `ping` | — | health check (tree + acting user) |
| `individual.get` | `xref` | xref, name, sex, `spouse_families`, `child_families` (`families` retained = spouse only, for back-compat) |
| `individual.parents` | `xref` | rich birth-family context: `father` + `mother` **each with their own `parents` (grandparents) and every `families` entry (spouse + children)**, plus the queried `individual`, `birth_family` xref, and `siblings`. One call to navigate up or sideways. |
| `individual.children` | `xref` | `children` across all spouse families (each tagged with its `family`) |
| `individual.siblings` | `xref` | `siblings` (other children of the birth family), de-duplicated, self excluded |
| `individual.spouses` | `xref` | `spouses`/partners across all spouse families |
| `family.get` | `xref` | husband, wife, children |
| `relationship.get` | `xref1`, `xref2`, `recursion?`, `ancestors?`, `max_paths?`, `lang?` | **all** relationship paths between two people — same engine as the web "Relationships" chart. Each result: `label` (e.g. "maternal grandfather"; reads *individual2 is the &lt;label&gt; of individual1*), `generations`, and the full `path` of alternating INDI/FAM nodes. `recursion` (default = tree's `RELATIONSHIP_RECURSION`, 0 = shortest only) controls how many alternative paths are enumerated; `ancestors=1` restricts to common-ancestor paths; `max_paths` caps the response (default 25, sets `truncated`); `lang` picks the label language (default = UI language, falls back to English). |
| `record.facts` | `xref` | every fact of an individual/family with its **fact id** (needed to edit/delete a specific fact) |
| `user.lookup` | `user_id` \| `email` \| `user_name` | user + tree role/link |
| `user.list` | `filter`=`all`\|`unverified`\|`unlinked`, `limit?` | list of users |

### Individuals
| op | params | does |
|---|---|---|
| `individual.create` | `given_name`, `surname`, `sex`=`M`/`F`/`U`, `birth_date?`, `birth_place?` | create a person |
| `individual.update` | `xref` + any of `new_given`, `new_surname`, `sex`, `birth_date`, `birth_place`, `death_date`, `death_place`, `occupation`, `note` | rename + add/replace facts |
| `individual.addFact` | `xref`, `tag` (e.g. `RESI`,`BAPM`,`EDUC`), `value?`, `date?`, `place?` | add an arbitrary fact |
| `individual.delete` | `xref` | delete a person **with no family links** (safety guard) |

### Relationships (create the person + wire the family both ways)
| op | params | does |
|---|---|---|
| `individual.addSpouse` | `xref` (existing) + (`spouse_xref` \| `given_name`,`surname`,`sex`) | add/link a spouse |
| `individual.addChild` | `parent_xref` + `given_name`,`surname`,`sex` (+ `family_xref?`) | add a child |
| `individual.addParent` | `xref` (child) + (`parent_xref` \| `given_name`,`surname`,`sex`) (+ `family_xref?`) | add a father/mother |

### Families
| op | params | does |
|---|---|---|
| `family.addEvent` | `family_xref`, `tag?`(=`MARR`), `date?`, `place?`, `value?` | add a marriage/other family event |
| `family.addChild` | `family_xref`, `child_xref` | link an **existing** individual into a family as a child |
| `family.delete` | `xref` | delete a family (webtrees auto-unlinks the spouses & children) |

### Media
| op | params | does |
|---|---|---|
| `media.create` | `title?`, (`file_url` \| `file`), `filename?`, `link_xref?` | create a media object: downloads `file_url` into the tree's media folder (server-side, follows redirects) **or** uses `file` (a name already in that folder); optionally links it to an individual/family |
| `media.get` | `xref` | the object's files (`file`, `title`, `format`) and the records it's linked to |
| `media.update` | `xref`, `title?`, `file_url?` | replace the title and/or the image file |
| `media.delete` | `xref` | delete a media object (webtrees auto-unlinks it) |
| `media.link` | `media_xref`, `xref` | link an existing media object to an individual/family |

### Edit any record (individual *or* family)
| op | params | does |
|---|---|---|
| `record.facts` | `xref` | list facts with their ids (call this first to get a `fact_id`) |
| `record.updateFact` | `xref`, `fact_id`, `gedcom` (a single level-1 fact, may include level-2+ sub-lines) | replace a fact |
| `record.deleteFact` | `xref`, `fact_id` | delete a fact (also works on link-facts) |
| `record.unlink` | `xref`, `other_xref` | remove **all** links between two records, both directions (detach a child, undo a spouse link, etc.) |

> **Note:** a fact's `fact_id` is a hash of its content, so it **changes after `updateFact`**. Re-run `record.facts` to get the new id before deleting/editing again.

### Users (admin)
| op | params | does |
|---|---|---|
| `user.create` | `user_name`, `real_name`, `email`, `password?` | create a user (random password if omitted) |
| `user.activate` | `user_id`, `xref?`, `role?`=`edit` | verify + admin-approve + link to an individual + set role |
| `user.update` | `user_id` + any of `real_name`, `email`, `user_name`, `password`, `role`, `verified`, `verified_by_admin`, `gedcomid` | change user fields |
| `user.delete` | `user_id` | delete a user (refuses site administrators) |

Roles (`role` / `canedit`): `none` < `access` (view) < `edit` < `accept` (moderator) < `admin` (tree manager).

### Forum (requires the companion `forum` module)
| op | params | does |
|---|---|---|
| `forum.listCategories` | — | list existing categories with topic counts |
| `forum.postTopic` | `title`, `body`, `category`, `author` (XREF), `broadcast?`=1, `attachment[]?`, `attachment_paths?`, `strict?` | create a topic on **author's** behalf — bypasses the keyword filter and the once-per-2h author rate limit, since the call is admin-token-gated. With `broadcast=1` (default) emails go out to every subscribed family member, exactly as if the author had posted through the UI |
| `forum.addComment` | `topic_id`, `text`, `author` (XREF), `notify?`=1, `attachment[]?`, `attachment_paths?`, `strict?` | append a comment on author's behalf and (with `notify=1`) email prior participants |
| `forum.deleteTopic` | `topic_id` | hard-delete a topic, all its comments, and all outbox rows for it |
| `forum.deleteComment` | `message_id` | delete a single comment; the topic too if it was the last one |

**Attachments** — accepted via either of:
- **Multipart upload** — POST the file(s) as `attachment` or `attachment[]` form fields (the same field names the in-UI form uses). One file or many. Per-file cap **10 MB**; extension allowlist mirrors the forum module.
- **Pre-staged paths** — `attachment_paths` is a JSON array (or a comma-separated string) of **absolute server-side paths** to files you already placed on the server (via SFTP/FTP/SSH). The API will MOVE each file (or copy on cross-filesystem) into the canonical `data/forum_attachments/<sub>/<name>` location and generate the public URL.

By default an attachment that fails validation (wrong extension, too big, missing) is **silently skipped** and the post still goes through — pass `strict=1` to make the whole call fail instead.

**`author` is the XREF**, e.g. `I1092`. It must match a row in `v_meran_user` (a webtrees-linked family member). For a posting on behalf of a non-linked user, link them first via `user.activate`.

**Broadcast behaviour** — `broadcast=1` (default) calls `ForumMailer::broadcastTopic`, which enqueues every eligible family member into `meran_forum_outbox` then drains under a soft 50 s budget; whatever doesn't finish is mopped up by the existing 5-min `forum-outbox-cron`. The response includes a count: `{ enqueued, sent, failed, remaining }`. Set `broadcast=0` to skip sending entirely (topic still goes live on the forum).

**`notify` on comments** — `notify=1` (default) calls `ForumMailer::notifyReply`, which emails only prior participants in that topic.

### Pending changes (admin)

Edits made by `edit`-role members go into webtrees' pending-changes queue and wait for a moderator. These ops list and clear that queue without using the web UI. They go through webtrees' own `PendingChangesService` (same path as the admin "accept all" button), so the gedcom records and the `wt_name` / `wt_link` indexes stay consistent.

| op | params | does |
| --- | --- | --- |
| `pending.list` | — | list every pending change: `change_id`, `xref`, `type` (INDI/FAM/…), `action` (`create`/`update`/`delete`), record `name`, `user_name`, `real_name`, `change_time`. Returns `count` (changes) and `records` (distinct xrefs) |
| `pending.acceptAll` | — | **approve every pending change** for the tree, in change order. Returns `{ accepted, records, pending_before, remaining }` |
| `pending.rejectAll` | — | reject (discard) every pending change for the tree |
| `pending.accept` | `xref` | approve all pending changes for one record |
| `pending.reject` | `xref` | reject all pending changes for one record |

```bash
# see what's waiting, then approve it all
api --data-urlencode "op=pending.list"
api --data-urlencode "op=pending.acceptAll"
```

---

## 5. Examples

```bash
SITE="https://your.tree.example"; TOKEN="your-token"
api() { curl -s -G "$SITE/index.php" --data-urlencode "route=/abf-api" \
        --data-urlencode "token=$TOKEN" "$@"; }

# create a person
api --data-urlencode "op=individual.create" \
    --data-urlencode "given_name=Maria" --data-urlencode "surname=Muster" \
    --data-urlencode "sex=F" --data-urlencode "birth_date=12 MAR 1980"

# add her husband (creates him + the family + both-way links)
api --data-urlencode "op=individual.addSpouse" --data-urlencode "xref=I123" \
    --data-urlencode "given_name=Hans" --data-urlencode "surname=Muster" --data-urlencode "sex=M"

# record their marriage
api --data-urlencode "op=family.addEvent" --data-urlencode "family_xref=F45" \
    --data-urlencode "date=10 JUN 2005" --data-urlencode "place=Wien"

# approve a pending registrant and link them to their person
api --data-urlencode "op=user.activate" --data-urlencode "user_id=42" \
    --data-urlencode "xref=I123" --data-urlencode "role=edit"

# list users who confirmed their email but aren't approved yet
api --data-urlencode "op=user.list" --data-urlencode "filter=unverified"

# post a forum topic on behalf of user I1092, then broadcast to family
api --data-urlencode "op=forum.postTopic" \
    --data-urlencode "title=Family reunion 2027 — save the date" \
    --data-urlencode "body=Hi everyone, blocking the weekend of ..." \
    --data-urlencode "category=General" --data-urlencode "author=I1092"

# same, but with a pre-staged PDF attachment and no broadcast
api --data-urlencode "op=forum.postTopic" \
    --data-urlencode "title=Photo album scan" --data-urlencode "body=See attached." \
    --data-urlencode "category=Family" --data-urlencode "author=I1092" \
    --data-urlencode "broadcast=0" \
    --data-urlencode 'attachment_paths=["/tmp/album.pdf"]'

# upload a file inline via multipart (note: POST not GET)
curl -s "$SITE/index.php?route=/abf-api" \
    -F "token=$TOKEN" -F "op=forum.postTopic" \
    -F "title=Inline upload demo" -F "body=One file via multipart." \
    -F "category=General" -F "author=I1092" \
    -F "attachment[]=@/path/to/file.pdf"

# reply to a topic and notify participants
api --data-urlencode "op=forum.addComment" --data-urlencode "topic_id=3592" \
    --data-urlencode "text=Thanks for sharing!" --data-urlencode "author=I45"
```

---

## 6. How it works / notes

- All writes run as the configured `run_as_user_id` and are force-accepted via
  `PendingChangesService`, so changes are live immediately (no pending-change queue).
- New records get webtrees 2.x **X-series xrefs** (e.g. `X105`); older records keep their original
  prefixes. Both are valid.
- Inputs going into GEDCOM (names, places, dates, fact values) are sanitised to prevent line-break
  / pointer injection. User fields (email etc.) keep their `@`.
- GEDCOM dates should be in webtrees/GEDCOM form, e.g. `12 MAR 1980`, `ABT 1900`, `1990`.

## 7. Limitations

- **GET only** (webtrees CSRF blocks POST).
- No record **merge** yet — use webtrees' built-in *Merge records* tool (it safely re-points all
  links). Everything else (edit/delete facts, unlink relationships, delete families) is supported.
- `individual.delete` only removes individuals with **no** family links (guard against orphaning a
  family); use `record.unlink` or `family.delete` to detach first.
- Media, sources, repositories and notes-as-records are not yet exposed (notes can be added inline
  via `individual.update` / `addFact`).

## License

Provided as-is. webtrees is GPL; treat this module accordingly.
