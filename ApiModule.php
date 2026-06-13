<?php

declare(strict_types=1);

namespace Fisharebest\Webtrees\Module;

use Fig\Http\Message\RequestMethodInterface;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Services\PendingChangesService;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\Services\EmailService;
use Fisharebest\Webtrees\Services\ModuleService;
use Fisharebest\Webtrees\Services\RelationshipService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\SiteUser;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionMethod;
use Throwable;

/**
 * A small, token-authenticated JSON API for managing users and individuals
 * on the (private) Meran family tree at abigfamily.org.
 *
 * SECURITY: the API token lives in module preferences (DB), NEVER in this repo.
 * SAFETY: boot() only registers routes. All work happens per-request in handle().
 * The route is tree-less + guest-reachable, then self-elevates (run_as_user_id)
 * so it can read/write the private tree. Every create/edit goes through
 * webtrees' own services (so wt_name/wt_link indexes + change-log stay correct)
 * and is force-accepted via PendingChangesService.
 *
 * Call: GET/POST /abf-api  with op=<operation> and token=<secret> (query param,
 * JSON body field, or X-Api-Token header). Responses are JSON.
 */
class ApiModule extends AbstractModule implements ModuleCustomInterface, RequestHandlerInterface
{
    use ModuleCustomTrait;

    protected const ROUTE_URL = '/abf-api';

    private const DEFAULT_TREE = 'meran.ged';

    public function boot(): void
    {
        $router = Registry::routeFactory()->routeMap();
        $router->get(static::class, static::ROUTE_URL, $this);
        $router->post(static::class . ':post', static::ROUTE_URL, $this);
        $router->allows(RequestMethodInterface::METHOD_POST);
    }

    public function title(): string
    {
        return I18N::translate('API');
    }

    public function description(): string
    {
        return 'Token-gated JSON API for managing users and individuals.';
    }

    public function customModuleAuthorName(): string
    {
        return 'Johannes Bolzano';
    }

    public function customModuleVersion(): string
    {
        return '1.0.0';
    }

    public function customModuleSupportUrl(): string
    {
        return 'https://github.com/jbh4x82/webtrees_api';
    }

    /**
     * @return array<string,string>
     */
    public function customTranslations(string $language): array
    {
        return [];
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $params = $this->params($request);

        // --- auth: token ---
        $configured = (string) $this->getPreference('api_token', '');
        $supplied   = (string) ($params['token'] ?? $request->getHeaderLine('X-Api-Token'));

        if ($configured === '' || !hash_equals($configured, $supplied)) {
            return $this->json(['ok' => false, 'error' => 'forbidden'], StatusCodeInterface::STATUS_FORBIDDEN);
        }

        $user_service = Registry::container()->get(UserService::class);

        // --- elevate (private tree needs an authenticated context) ---
        $run_as = (int) $this->getPreference('run_as_user_id', '0');
        if ($run_as > 0) {
            $run_as_user = $user_service->find($run_as);
            if ($run_as_user !== null) {
                Auth::login($run_as_user);
            }
        }

        $tree = $this->resolveTree((string) ($params['tree'] ?? self::DEFAULT_TREE));
        if ($tree === null) {
            return $this->json(['ok' => false, 'error' => 'tree not found'], StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR);
        }

        $pcs = Registry::container()->get(PendingChangesService::class);
        $op  = (string) ($params['op'] ?? '');

        try {
            switch ($op) {
                case 'ping':
                    return $this->json(['ok' => true, 'op' => 'ping', 'tree' => $tree->name(), 'acting_as' => Auth::user()->userName()]);

                case 'user.lookup':
                    return $this->userLookup($user_service, $tree, $params);

                case 'user.activate':
                    return $this->userActivate($user_service, $tree, $params, $request);

                case 'user.create':
                    return $this->userCreate($user_service, $params);

                case 'user.delete':
                    return $this->userDelete($user_service, $params);

                case 'individual.get':
                    return $this->individualGet($tree, $params);

                case 'individual.create':
                    return $this->individualCreate($tree, $pcs, $params);

                case 'individual.addSpouse':
                    return $this->individualAddSpouse($tree, $pcs, $params);

                case 'individual.delete':
                    return $this->individualDelete($tree, $pcs, $params);

                case 'individual.update':
                    return $this->individualUpdate($tree, $pcs, $params);

                case 'individual.addChild':
                    return $this->individualAddChild($tree, $pcs, $params);

                case 'individual.addParent':
                    return $this->individualAddParent($tree, $pcs, $params);

                case 'individual.addFact':
                    return $this->individualAddFact($tree, $pcs, $params);

                case 'family.get':
                    return $this->familyGet($tree, $params);

                case 'relationship.get':
                    return $this->relationshipGet($tree, $params);

                case 'family.addEvent':
                    return $this->familyAddEvent($tree, $pcs, $params);

                case 'family.addChild':
                    return $this->familyAddChild($tree, $pcs, $params);

                case 'family.delete':
                    return $this->familyDelete($tree, $pcs, $params);

                case 'record.facts':
                    return $this->recordFacts($tree, $params);

                case 'record.updateFact':
                    return $this->recordUpdateFact($tree, $pcs, $params);

                case 'record.deleteFact':
                    return $this->recordDeleteFact($tree, $pcs, $params);

                case 'record.unlink':
                    return $this->recordUnlink($tree, $pcs, $params);

                case 'user.update':
                    return $this->userUpdate($user_service, $tree, $params);

                case 'user.list':
                    return $this->userList($user_service, $tree, $params);

                case 'forum.listCategories':
                    return $this->forumListCategories();

                case 'forum.postTopic':
                    return $this->forumPostTopic($tree, $request, $params);

                case 'forum.addComment':
                    return $this->forumAddComment($tree, $request, $params);

                case 'forum.deleteTopic':
                    return $this->forumDeleteTopic($params);

                case 'forum.deleteComment':
                    return $this->forumDeleteComment($params);

                case 'pending.list':
                    return $this->pendingList($tree, $params);

                case 'pending.acceptAll':
                    return $this->pendingAcceptAll($tree, $pcs);

                case 'pending.rejectAll':
                    return $this->pendingRejectAll($tree, $pcs);

                case 'pending.accept':
                    return $this->pendingAccept($tree, $pcs, $params);

                case 'pending.reject':
                    return $this->pendingReject($tree, $pcs, $params);

                default:
                    return $this->json(['ok' => false, 'error' => 'unknown op: ' . $op], StatusCodeInterface::STATUS_BAD_REQUEST);
            }
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ───────────────────────────── user ops ─────────────────────────────

    /**
     * @param array<string,mixed> $params
     */
    private function userLookup(UserService $user_service, Tree $tree, array $params): ResponseInterface
    {
        $user = null;
        if (isset($params['user_id'])) {
            $user = $user_service->find((int) $params['user_id']);
        } elseif (isset($params['email'])) {
            $user = $user_service->findByEmail((string) $params['email']);
        } elseif (isset($params['user_name'])) {
            $user = $user_service->findByUserName((string) $params['user_name']);
        }

        if ($user === null) {
            return $this->json(['ok' => false, 'error' => 'user not found']);
        }

        return $this->json(['ok' => true, 'user' => $this->userInfo($user, $tree)]);
    }

    /**
     * Verify + admin-approve + link to an individual + grant the "edit" role.
     *
     * @param array<string,mixed> $params
     */
    private function userActivate(UserService $user_service, Tree $tree, array $params, ServerRequestInterface $request): ResponseInterface
    {
        $user = $user_service->find((int) ($params['user_id'] ?? 0));
        if ($user === null) {
            return $this->json(['ok' => false, 'error' => 'user not found']);
        }

        $xref = $this->clean((string) ($params['xref'] ?? ''));
        $role = (string) ($params['role'] ?? 'edit');
        if (!in_array($role, ['none', 'access', 'edit', 'accept', 'admin'], true)) {
            $role = 'edit';
        }

        // Native approval-email transition: send only when the account was NOT
        // previously admin-approved (mirrors core UserEditAction). Suppress with notify=0.
        $was_approved = $user->getPreference(UserInterface::PREF_IS_ACCOUNT_APPROVED) === '1';
        $notify       = !isset($params['notify']) || (string) $params['notify'] === '1' || $params['notify'] === true;
        $approval_email = null;

        $user->setPreference('verified', '1');
        $user->setPreference('verified_by_admin', '1');
        $tree->setUserPreference($user, 'canedit', $role);

        if ($xref !== '') {
            $tree->setUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF, $xref);
            $tree->setUserPreference($user, 'rootid', $xref);
        }

        if ($was_approved) {
            $approval_email = 'skipped (already approved)';
        } elseif (!$notify) {
            $approval_email = 'skipped (notify=0)';
        } else {
            // Exactly what core does in UserEditAction when an admin approves a user.
            try {
                I18N::init($user->getPreference(UserInterface::PREF_LANGUAGE, 'en-US'));
                $base_url      = Validator::attributes($request)->string('base_url');
                $email_service = Registry::container()->get(EmailService::class);
                $ok = $email_service->send(
                    new SiteUser(),
                    $user,
                    Auth::user(),
                    /* I18N: %s is a server name/URL */
                    I18N::translate('New user at %s', $base_url),
                    view('emails/approve-user-text', ['user' => $user, 'base_url' => $base_url]),
                    view('emails/approve-user-html', ['user' => $user, 'base_url' => $base_url])
                );
                $approval_email = $ok ? 'sent' : 'failed';
            } catch (Throwable $e) {
                $approval_email = ['error' => $e->getMessage()];
            }
        }

        return $this->json(['ok' => true, 'approval_email' => $approval_email, 'user' => $this->userInfo($user, $tree)]);
    }

    /**
     * @param array<string,mixed> $params
     */
    private function userCreate(UserService $user_service, array $params): ResponseInterface
    {
        $user_name = $this->cleanLine((string) ($params['user_name'] ?? ''));
        $real_name = $this->cleanLine((string) ($params['real_name'] ?? ''));
        $email     = $this->cleanLine((string) ($params['email'] ?? ''));
        $password  = (string) ($params['password'] ?? bin2hex(random_bytes(12)));

        if ($user_name === '' || $real_name === '' || $email === '') {
            return $this->json(['ok' => false, 'error' => 'user_name, real_name, email required']);
        }
        if ($user_service->findByUserName($user_name) !== null || $user_service->findByEmail($email) !== null) {
            return $this->json(['ok' => false, 'error' => 'user_name or email already exists']);
        }

        $user = $user_service->create($user_name, $real_name, $email, $password);

        return $this->json(['ok' => true, 'user_id' => $user->id(), 'user_name' => $user->userName()]);
    }

    /**
     * @param array<string,mixed> $params
     */
    private function userDelete(UserService $user_service, array $params): ResponseInterface
    {
        $user = $user_service->find((int) ($params['user_id'] ?? 0));
        if ($user === null) {
            return $this->json(['ok' => false, 'error' => 'user not found']);
        }
        if ((int) $user->getPreference('canadmin') === 1) {
            return $this->json(['ok' => false, 'error' => 'refusing to delete a site administrator']);
        }

        $id = $user->id();
        $user_service->delete($user);

        return $this->json(['ok' => true, 'deleted_user_id' => $id]);
    }

    // ───────────────────────────── individual ops ─────────────────────────────

    /**
     * @param array<string,mixed> $params
     */
    private function individualGet(Tree $tree, array $params): ResponseInterface
    {
        $xref = $this->clean((string) ($params['xref'] ?? ''));
        $indi = Registry::individualFactory()->make($xref, $tree);
        if ($indi === null) {
            return $this->json(['ok' => false, 'error' => 'individual not found']);
        }

        return $this->json(['ok' => true, 'individual' => $this->indiInfo($indi)]);
    }

    /**
     * All relationship paths between two individuals.
     *
     * Uses webtrees' own RelationshipsChartModule::calculateRelationships()
     * (a Dijkstra over the FAMS/FAMC graph that returns every shortest path,
     * plus alternative paths through other families up to `recursion`) and
     * RelationshipService::nameFromPath() for the human-readable label of each.
     * This is the same engine behind the "Relationships" chart in the web UI,
     * so results match what a family member would see there — no raw SQL.
     *
     * Params:
     *   xref1, xref2  (required) the two individuals
     *   recursion     (int) how many alternative paths to enumerate beyond the
     *                 shortest; defaults to the tree's RELATIONSHIP_RECURSION
     *                 preference (99 = unlimited), capped at it. 0 = shortest only.
     *   ancestors     (1) restrict to paths through a common ancestor.
     *   max_paths     (int, default 25, cap 200) response size guard.
     *   lang          (e.g. "en-GB", "de") label language; default = UI language,
     *                 falling back to English.
     *
     * Each relationship's `label` reads: "individual2 is the <label> of individual1".
     *
     * @param array<string,mixed> $params
     */
    private function relationshipGet(Tree $tree, array $params): ResponseInterface
    {
        $xref1 = $this->clean((string) ($params['xref1'] ?? ''));
        $xref2 = $this->clean((string) ($params['xref2'] ?? ''));

        $i1 = Registry::individualFactory()->make($xref1, $tree);
        $i2 = Registry::individualFactory()->make($xref2, $tree);
        if ($i1 === null || $i2 === null) {
            return $this->json(['ok' => false, 'error' => 'individual(s) not found']);
        }

        $language = $this->relationshipLanguage((string) ($params['lang'] ?? ''));

        // Year out of a webtrees Date (null when unknown).
        $yr = static function ($date): ?int {
            if ($date === null || !$date->isOK()) {
                return null;
            }
            $y = (int) $date->minimumDate()->year();
            return $y !== 0 ? $y : null;
        };
        $brief = static function (Individual $i) use ($yr): array {
            return [
                'xref'  => $i->xref(),
                'name'  => strip_tags($i->fullName()),
                'sex'   => $i->sex(),
                'birth' => $yr($i->getBirthDate()),
                'death' => $yr($i->getDeathDate()),
            ];
        };

        // Same person.
        if ($xref1 === $xref2) {
            return $this->json([
                'ok'            => true,
                'op'            => 'relationship.get',
                'individual1'   => $brief($i1),
                'individual2'   => $brief($i2),
                'count'         => 1,
                'truncated'     => false,
                'closest'       => 'self',
                'relationships' => [[
                    'label'       => 'self',
                    'generations' => 0,
                    'path'        => [$brief($i1) + ['type' => 'INDI']],
                    'lineage'     => [$brief($i1) + ['relation_to_previous' => null]],
                ]],
            ]);
        }

        $tree_recursion = (int) $tree->getPreference('RELATIONSHIP_RECURSION', '99');
        $recursion      = isset($params['recursion']) ? (int) $params['recursion'] : $tree_recursion;
        $recursion      = max(0, min($recursion, $tree_recursion));

        $ancestors = isset($params['ancestors']) && ((string) $params['ancestors'] === '1' || $params['ancestors'] === true);

        $max_paths = (int) ($params['max_paths'] ?? 25);
        $max_paths = max(1, min($max_paths, 200));

        // Build the chart module directly (works regardless of enabled-state).
        $relationship_service = Registry::container()->get(RelationshipService::class);
        $tree_service         = Registry::container()->get(TreeService::class);
        $chart                = new RelationshipsChartModule($relationship_service, $tree_service);

        $method = new ReflectionMethod(RelationshipsChartModule::class, 'calculateRelationships');
        $method->setAccessible(true);
        // Returns array of paths; each path is an array of alternating
        // INDI/FAM xref strings (node 0 = individual1 … last = individual2).
        $paths = $method->invoke($chart, $i1, $i2, $recursion, $ancestors);

        $relationships = [];
        $truncated     = false;

        foreach ($paths as $path_xrefs) {
            if (count($relationships) >= $max_paths) {
                $truncated = true;
                break;
            }

            $nodes    = [];
            $node_out = [];
            $ok       = true;
            foreach (array_values($path_xrefs) as $i => $x) {
                $is_indi = ($i % 2 === 0);
                $rec     = $is_indi
                    ? Registry::individualFactory()->make((string) $x, $tree)
                    : Registry::familyFactory()->make((string) $x, $tree);
                if ($rec === null) {
                    $ok = false;
                    break;
                }
                $nodes[] = $rec;
                if ($is_indi) {
                    $node_out[] = [
                        'xref'  => (string) $x,
                        'name'  => strip_tags($rec->fullName()),
                        'type'  => 'INDI',
                        'sex'   => $rec->sex(),
                        'birth' => $yr($rec->getBirthDate()),
                        'death' => $yr($rec->getDeathDate()),
                    ];
                } else {
                    $node_out[] = ['xref' => (string) $x, 'name' => strip_tags($rec->fullName()), 'type' => 'FAM'];
                }
            }
            if (!$ok || $nodes === []) {
                continue;
            }

            $label = $language !== null ? $relationship_service->nameFromPath($nodes, $language) : '';

            // Walk just the individuals (even indices) and label how each
            // connects to the previous one (father/mother/son/daughter/spouse/sibling).
            $lineage = [];
            for ($n = 0; $n < count($nodes); $n += 2) {
                $ind   = $nodes[$n];
                $entry = [
                    'xref'                 => $ind->xref(),
                    'name'                 => strip_tags($ind->fullName()),
                    'sex'                  => $ind->sex(),
                    'birth'                => $yr($ind->getBirthDate()),
                    'death'                => $yr($ind->getDeathDate()),
                    'relation_to_previous' => $n >= 2 ? $this->stepRelation($nodes[$n - 2], $nodes[$n - 1], $ind) : null,
                ];
                $lineage[] = $entry;
            }

            $relationships[] = [
                'label'       => $label,
                'generations' => intdiv(count($node_out) - 1, 2), // number of family edges traversed
                'path'        => $node_out,
                'lineage'     => $lineage,
            ];
        }

        // Closest (fewest nodes) first.
        usort($relationships, static fn (array $a, array $b): int => count($a['path']) <=> count($b['path']));

        return $this->json([
            'ok'            => true,
            'op'            => 'relationship.get',
            'individual1'   => $brief($i1),
            'individual2'   => $brief($i2),
            'reading'       => 'label = individual2 is the <label> of individual1',
            'count'         => count($relationships),
            'truncated'     => $truncated,
            'closest'       => $relationships[0]['label'] ?? '',
            'relationships' => $relationships,
        ]);
    }

    /**
     * Label how `$cur` connects to `$prev` across the linking family `$fam`,
     * sexed where possible (father/mother, son/daughter, brother/sister).
     */
    private function stepRelation(Individual $prev, Family $fam, Individual $cur): string
    {
        $childXrefs  = $fam->children()->map(static fn (Individual $c): string => $c->xref())->all();
        $spouseXrefs = $fam->spouses()->map(static fn (Individual $s): string => $s->xref())->all();

        $prevChild  = in_array($prev->xref(), $childXrefs, true);
        $prevSpouse = in_array($prev->xref(), $spouseXrefs, true);
        $curChild   = in_array($cur->xref(), $childXrefs, true);
        $curSpouse  = in_array($cur->xref(), $spouseXrefs, true);

        $sex = $cur->sex();

        if ($prevChild && $curSpouse) {
            return $sex === 'M' ? 'father' : ($sex === 'F' ? 'mother' : 'parent');
        }
        if ($prevSpouse && $curChild) {
            return $sex === 'M' ? 'son' : ($sex === 'F' ? 'daughter' : 'child');
        }
        if ($prevSpouse && $curSpouse) {
            return 'spouse';
        }
        if ($prevChild && $curChild) {
            return $sex === 'M' ? 'brother' : ($sex === 'F' ? 'sister' : 'sibling');
        }

        return 'relative';
    }

    /**
     * Pick a language module for relationship labels: requested `lang`, else the
     * current UI language, else English, else whatever exists.
     */
    private function relationshipLanguage(string $tag): ?ModuleLanguageInterface
    {
        $modules = Registry::container()->get(ModuleService::class)
            ->findByInterface(ModuleLanguageInterface::class, true);

        $pick = static fn (string $t): ?ModuleLanguageInterface => $modules
            ->first(static fn (ModuleLanguageInterface $l): bool => $l->locale()->languageTag() === $t);

        if ($tag !== '' && ($m = $pick($tag)) !== null) {
            return $m;
        }
        if (($m = $pick(I18N::languageTag())) !== null) {
            return $m;
        }
        foreach (['en-GB', 'en-US', 'en'] as $t) {
            if (($m = $pick($t)) !== null) {
                return $m;
            }
        }

        return $modules->first();
    }

    /**
     * @param array<string,mixed> $params
     */
    private function individualCreate(Tree $tree, PendingChangesService $pcs, array $params): ResponseInterface
    {
        $indi = $this->makeIndividual($tree, $pcs, $params);
        if ($indi === null) {
            return $this->json(['ok' => false, 'error' => 'given_name and surname required']);
        }

        return $this->json(['ok' => true, 'individual' => $this->indiInfo($indi)]);
    }

    /**
     * Add a spouse to an existing individual. Either create a new spouse
     * (given_name/surname/sex) or link an existing one (spouse_xref).
     *
     * @param array<string,mixed> $params
     */
    private function individualAddSpouse(Tree $tree, PendingChangesService $pcs, array $params): ResponseInterface
    {
        $xref     = $this->clean((string) ($params['xref'] ?? ''));
        $existing = Registry::individualFactory()->make($xref, $tree);
        if ($existing === null) {
            return $this->json(['ok' => false, 'error' => 'existing individual (xref) not found']);
        }

        $spouse_xref = $this->clean((string) ($params['spouse_xref'] ?? ''));
        if ($spouse_xref !== '') {
            $spouse = Registry::individualFactory()->make($spouse_xref, $tree);
            if ($spouse === null) {
                return $this->json(['ok' => false, 'error' => 'spouse_xref not found']);
            }
        } else {
            $spouse = $this->makeIndividual($tree, $pcs, $params);
            if ($spouse === null) {
                return $this->json(['ok' => false, 'error' => 'spouse given_name and surname required']);
            }
        }

        // Decide HUSB/WIFE by sex.
        if ($spouse->sex() === 'M' || $existing->sex() === 'F') {
            $husb = $spouse;
            $wife = $existing;
        } else {
            $husb = $existing;
            $wife = $spouse;
        }

        $family = $tree->createFamily("0 @@ FAM\n1 HUSB @" . $husb->xref() . "@\n1 WIFE @" . $wife->xref() . '@');
        $pcs->acceptRecord($family);

        $existing = $this->reload($existing);
        $spouse   = $this->reload($spouse);
        $existing->createFact('1 FAMS @' . $family->xref() . '@', false);
        $spouse->createFact('1 FAMS @' . $family->xref() . '@', false);
        $pcs->acceptRecord($this->reload($existing));
        $pcs->acceptRecord($this->reload($spouse));

        return $this->json([
            'ok'       => true,
            'family'   => $family->xref(),
            'husband'  => $husb->xref(),
            'wife'     => $wife->xref(),
            'spouse'   => $this->indiInfo($this->reload($spouse)),
            'existing' => $this->indiInfo($this->reload($existing)),
        ]);
    }

    /**
     * @param array<string,mixed> $params
     */
    private function individualDelete(Tree $tree, PendingChangesService $pcs, array $params): ResponseInterface
    {
        $xref = $this->clean((string) ($params['xref'] ?? ''));
        $indi = Registry::individualFactory()->make($xref, $tree);
        if ($indi === null) {
            return $this->json(['ok' => false, 'error' => 'individual not found']);
        }
        // Safety: only delete childless, family-less individuals (test dummies).
        if ($indi->childFamilies()->isNotEmpty() || $indi->spouseFamilies()->isNotEmpty()) {
            return $this->json(['ok' => false, 'error' => 'refusing to delete: individual is linked to a family']);
        }

        $indi->deleteRecord();
        $pcs->acceptRecord($indi);

        return $this->json(['ok' => true, 'deleted' => $xref]);
    }

    /**
     * Add/replace facts on an existing individual: rename, sex, birth, death, occupation, note.
     *
     * @param array<string,mixed> $params
     */
    private function individualUpdate(Tree $tree, PendingChangesService $pcs, array $params): ResponseInterface
    {
        $xref = $this->clean((string) ($params['xref'] ?? ''));
        $indi = Registry::individualFactory()->make($xref, $tree);
        if ($indi === null) {
            return $this->json(['ok' => false, 'error' => 'individual not found']);
        }

        $changed = [];

        $new_given   = $this->clean((string) ($params['new_given'] ?? ''));
        $new_surname = $this->clean((string) ($params['new_surname'] ?? ''));
        if ($new_given !== '' || $new_surname !== '') {
            $name_fact = $indi->facts(['NAME'])->first();
            $gedcom    = '1 NAME ' . $new_given . ' /' . $new_surname . '/';
            if ($name_fact !== null) {
                $indi->updateFact($name_fact->id(), $gedcom, false);
            } else {
                $indi->createFact($gedcom, false);
            }
            $pcs->acceptRecord($indi);
            $indi      = $this->reload($indi);
            $changed[] = 'name';
        }

        $sex = strtoupper($this->clean((string) ($params['sex'] ?? '')));
        if (in_array($sex, ['M', 'F', 'U'], true)) {
            $sex_fact = $indi->facts(['SEX'])->first();
            if ($sex_fact !== null) {
                $indi->updateFact($sex_fact->id(), '1 SEX ' . $sex, false);
            } else {
                $indi->createFact('1 SEX ' . $sex, false);
            }
            $pcs->acceptRecord($indi);
            $indi      = $this->reload($indi);
            $changed[] = 'sex';
        }

        $birth = $this->eventGedcom('BIRT', $params, 'birth_date', 'birth_place');
        if ($birth !== '') {
            $indi      = $this->applyFact($indi, $birth, $pcs);
            $changed[] = 'birth';
        }
        $death = $this->eventGedcom('DEAT', $params, 'death_date', 'death_place');
        if ($death !== '') {
            $indi      = $this->applyFact($indi, $death, $pcs);
            $changed[] = 'death';
        }
        $occ = $this->clean((string) ($params['occupation'] ?? ''));
        if ($occ !== '') {
            $indi      = $this->applyFact($indi, '1 OCCU ' . $occ, $pcs);
            $changed[] = 'occupation';
        }
        $note = $this->clean((string) ($params['note'] ?? ''));
        if ($note !== '') {
            $indi      = $this->applyFact($indi, '1 NOTE ' . $note, $pcs);
            $changed[] = 'note';
        }

        if ($changed === []) {
            return $this->json(['ok' => false, 'error' => 'no update fields provided']);
        }

        return $this->json(['ok' => true, 'updated' => $changed, 'individual' => $this->indiInfo($this->reload($indi))]);
    }

    /**
     * Add a child to an existing parent (to their single couple-family, a named
     * family_xref, or a new single-parent family).
     *
     * @param array<string,mixed> $params
     */
    private function individualAddChild(Tree $tree, PendingChangesService $pcs, array $params): ResponseInterface
    {
        $parent = Registry::individualFactory()->make($this->clean((string) ($params['parent_xref'] ?? '')), $tree);
        if ($parent === null) {
            return $this->json(['ok' => false, 'error' => 'parent_xref not found']);
        }

        $given   = $this->clean((string) ($params['given_name'] ?? ''));
        $surname = $this->clean((string) ($params['surname'] ?? ''));
        $sex     = strtoupper($this->clean((string) ($params['sex'] ?? 'U')));
        if ($given === '' || $surname === '') {
            return $this->json(['ok' => false, 'error' => 'given_name and surname required']);
        }
        if (!in_array($sex, ['M', 'F', 'U'], true)) {
            $sex = 'U';
        }
        $name_sex = "\n1 NAME " . $given . ' /' . $surname . "/\n1 SEX " . $sex;

        $family      = null;
        $family_xref = $this->clean((string) ($params['family_xref'] ?? ''));
        if ($family_xref !== '') {
            $family = Registry::familyFactory()->make($family_xref, $tree);
            if ($family === null) {
                return $this->json(['ok' => false, 'error' => 'family_xref not found']);
            }
        } elseif ($parent->spouseFamilies()->count() === 1) {
            $family = $parent->spouseFamilies()->first();
        }

        if ($family !== null) {
            $child = $tree->createIndividual('0 @@ INDI' . "\n1 FAMC @" . $family->xref() . '@' . $name_sex);
            $pcs->acceptRecord($child);
            $family = Registry::familyFactory()->make($family->xref(), $tree);
            $family->createFact('1 CHIL @' . $child->xref() . '@', false);
            $pcs->acceptRecord(Registry::familyFactory()->make($family->xref(), $tree));
        } else {
            $child = $tree->createIndividual('0 @@ INDI' . $name_sex);
            $pcs->acceptRecord($child);
            $link   = $parent->sex() === 'F' ? 'WIFE' : 'HUSB';
            $family = $tree->createFamily('0 @@ FAM' . "\n1 " . $link . ' @' . $parent->xref() . "@\n1 CHIL @" . $child->xref() . '@');
            $pcs->acceptRecord($family);
            $parent = $this->reload($parent);
            $parent->createFact('1 FAMS @' . $family->xref() . '@', false);
            $pcs->acceptRecord($this->reload($parent));
            $child = $this->reload($child);
            $child->createFact('1 FAMC @' . $family->xref() . '@', false);
            $pcs->acceptRecord($this->reload($child));
        }

        return $this->json([
            'ok'     => true,
            'child'  => $this->indiInfo($this->reload($child)),
            'family' => $family->xref(),
            'parent' => $parent->xref(),
        ]);
    }

    /**
     * Add a parent (father/mother) to an existing individual — to their
     * existing parents-family, a named family_xref, or a new one.
     *
     * @param array<string,mixed> $params
     */
    private function individualAddParent(Tree $tree, PendingChangesService $pcs, array $params): ResponseInterface
    {
        $child = Registry::individualFactory()->make($this->clean((string) ($params['xref'] ?? '')), $tree);
        if ($child === null) {
            return $this->json(['ok' => false, 'error' => 'xref (child) not found']);
        }

        $parent_xref = $this->clean((string) ($params['parent_xref'] ?? ''));
        if ($parent_xref !== '') {
            $parent = Registry::individualFactory()->make($parent_xref, $tree);
            if ($parent === null) {
                return $this->json(['ok' => false, 'error' => 'parent_xref not found']);
            }
        } else {
            $parent = $this->makeIndividual($tree, $pcs, $params);
            if ($parent === null) {
                return $this->json(['ok' => false, 'error' => 'parent given_name and surname required']);
            }
        }

        $link = $parent->sex() === 'F' ? 'WIFE' : 'HUSB';

        $family      = null;
        $family_xref = $this->clean((string) ($params['family_xref'] ?? ''));
        if ($family_xref !== '') {
            $family = Registry::familyFactory()->make($family_xref, $tree);
            if ($family === null) {
                return $this->json(['ok' => false, 'error' => 'family_xref not found']);
            }
        } elseif ($child->childFamilies()->count() === 1) {
            $family = $child->childFamilies()->first();
        }

        if ($family !== null) {
            $family->createFact('1 ' . $link . ' @' . $parent->xref() . '@', false);
            $pcs->acceptRecord($this->reloadFamily($family, $tree));
            $parent = $this->reload($parent);
            $parent->createFact('1 FAMS @' . $family->xref() . '@', false);
            $pcs->acceptRecord($this->reload($parent));
        } else {
            $family = $tree->createFamily('0 @@ FAM' . "\n1 CHIL @" . $child->xref() . "@\n1 " . $link . ' @' . $parent->xref() . '@');
            $pcs->acceptRecord($family);
            $child = $this->reload($child);
            $child->createFact('1 FAMC @' . $family->xref() . '@', false);
            $pcs->acceptRecord($this->reload($child));
            $parent = $this->reload($parent);
            $parent->createFact('1 FAMS @' . $family->xref() . '@', false);
            $pcs->acceptRecord($this->reload($parent));
        }

        return $this->json([
            'ok'     => true,
            'parent' => $this->indiInfo($this->reload($parent)),
            'family' => $family->xref(),
            'child'  => $child->xref(),
        ]);
    }

    /**
     * Add an arbitrary fact to an individual (e.g. RESI, BAPM, EDUC, OCCU)
     * with optional value, date and place.
     *
     * @param array<string,mixed> $params
     */
    private function individualAddFact(Tree $tree, PendingChangesService $pcs, array $params): ResponseInterface
    {
        $indi = Registry::individualFactory()->make($this->clean((string) ($params['xref'] ?? '')), $tree);
        if ($indi === null) {
            return $this->json(['ok' => false, 'error' => 'individual not found']);
        }

        $gedcom = $this->factGedcom($params);
        if ($gedcom === null) {
            return $this->json(['ok' => false, 'error' => 'valid tag required (2-10 letters), e.g. RESI/BAPM/EDUC']);
        }

        $indi = $this->applyFact($indi, $gedcom, $pcs);

        return $this->json(['ok' => true, 'individual' => $this->indiInfo($indi)]);
    }

    /**
     * @param array<string,mixed> $params
     */
    private function familyGet(Tree $tree, array $params): ResponseInterface
    {
        $family = Registry::familyFactory()->make($this->clean((string) ($params['xref'] ?? '')), $tree);
        if ($family === null) {
            return $this->json(['ok' => false, 'error' => 'family not found']);
        }

        return $this->json(['ok' => true, 'family' => $this->familyInfo($family)]);
    }

    /**
     * Add an event to a family (default MARR) with optional date/place.
     *
     * @param array<string,mixed> $params
     */
    private function familyAddEvent(Tree $tree, PendingChangesService $pcs, array $params): ResponseInterface
    {
        $family = Registry::familyFactory()->make($this->clean((string) ($params['family_xref'] ?? $params['xref'] ?? '')), $tree);
        if ($family === null) {
            return $this->json(['ok' => false, 'error' => 'family not found']);
        }

        if (!isset($params['tag'])) {
            $params['tag'] = 'MARR';
        }
        $gedcom = $this->factGedcom($params);
        if ($gedcom === null) {
            return $this->json(['ok' => false, 'error' => 'valid tag required (2-10 letters), e.g. MARR/DIV']);
        }

        $family->createFact($gedcom, false);
        $pcs->acceptRecord($this->reloadFamily($family, $tree));

        return $this->json(['ok' => true, 'family' => $this->familyInfo($this->reloadFamily($family, $tree))]);
    }

    /**
     * Link an EXISTING individual as a child of a family.
     *
     * @param array<string,mixed> $params
     */
    private function familyAddChild(Tree $tree, PendingChangesService $pcs, array $params): ResponseInterface
    {
        $family = Registry::familyFactory()->make($this->clean((string) ($params['family_xref'] ?? '')), $tree);
        if ($family === null) {
            return $this->json(['ok' => false, 'error' => 'family_xref not found']);
        }
        $child = Registry::individualFactory()->make($this->clean((string) ($params['child_xref'] ?? '')), $tree);
        if ($child === null) {
            return $this->json(['ok' => false, 'error' => 'child_xref not found']);
        }

        $family->createFact('1 CHIL @' . $child->xref() . '@', false);
        $pcs->acceptRecord($this->reloadFamily($family, $tree));
        $child = $this->reload($child);
        $child->createFact('1 FAMC @' . $family->xref() . '@', false);
        $pcs->acceptRecord($this->reload($child));

        return $this->json(['ok' => true, 'family' => $this->familyInfo($this->reloadFamily($family, $tree))]);
    }

    /**
     * Delete a family record. webtrees unlinks the spouses/children automatically.
     *
     * @param array<string,mixed> $params
     */
    private function familyDelete(Tree $tree, PendingChangesService $pcs, array $params): ResponseInterface
    {
        $family = Registry::familyFactory()->make($this->clean((string) ($params['xref'] ?? '')), $tree);
        if ($family === null) {
            return $this->json(['ok' => false, 'error' => 'family not found']);
        }

        $xref = $family->xref();
        $family->deleteRecord();
        $pcs->acceptRecord($family);

        return $this->json(['ok' => true, 'deleted' => $xref]);
    }

    /**
     * List a record's facts with their ids (needed to update/delete a specific fact).
     *
     * @param array<string,mixed> $params
     */
    private function recordFacts(Tree $tree, array $params): ResponseInterface
    {
        $record = $this->resolveRecord($tree, (string) ($params['xref'] ?? ''));
        if ($record === null) {
            return $this->json(['ok' => false, 'error' => 'record not found']);
        }

        $facts = $record->facts([], false, null, true)
            ->map(static fn (Fact $f): array => ['id' => $f->id(), 'tag' => $f->tag(), 'gedcom' => $f->gedcom()])
            ->values()
            ->all();

        return $this->json(['ok' => true, 'xref' => $record->xref(), 'facts' => $facts]);
    }

    /**
     * Replace one fact (by id) with new GEDCOM. The gedcom must be a single
     * level-1 fact (it may contain level 2+ sub-lines).
     *
     * @param array<string,mixed> $params
     */
    private function recordUpdateFact(Tree $tree, PendingChangesService $pcs, array $params): ResponseInterface
    {
        $record = $this->resolveRecord($tree, (string) ($params['xref'] ?? ''));
        if ($record === null) {
            return $this->json(['ok' => false, 'error' => 'record not found']);
        }
        $fact_id = $this->clean((string) ($params['fact_id'] ?? ''));
        if ($fact_id === '') {
            return $this->json(['ok' => false, 'error' => 'fact_id required']);
        }

        $gedcom = str_replace(["\r", "\t"], '', (string) ($params['gedcom'] ?? ''));
        if (!str_starts_with($gedcom, '1 ') || str_contains($gedcom, "\n0 ")) {
            return $this->json(['ok' => false, 'error' => 'gedcom must be a single level-1 fact (start "1 ", no level-0 lines)']);
        }

        $record->updateFact($fact_id, $gedcom, true);
        $pcs->acceptRecord($record);

        return $this->json(['ok' => true, 'xref' => $record->xref(), 'fact_id' => $fact_id]);
    }

    /**
     * Delete one fact (by id). Works for events and for link-facts (CHIL/FAMS/etc.).
     *
     * @param array<string,mixed> $params
     */
    private function recordDeleteFact(Tree $tree, PendingChangesService $pcs, array $params): ResponseInterface
    {
        $record = $this->resolveRecord($tree, (string) ($params['xref'] ?? ''));
        if ($record === null) {
            return $this->json(['ok' => false, 'error' => 'record not found']);
        }
        $fact_id = $this->clean((string) ($params['fact_id'] ?? ''));
        if ($fact_id === '') {
            return $this->json(['ok' => false, 'error' => 'fact_id required']);
        }

        $record->deleteFact($fact_id, true);
        $pcs->acceptRecord($record);

        return $this->json(['ok' => true, 'xref' => $record->xref(), 'deleted_fact' => $fact_id]);
    }

    /**
     * Remove ALL links between two records (both directions) — e.g. detach a
     * child from a family, or undo a spouse link.
     *
     * @param array<string,mixed> $params
     */
    private function recordUnlink(Tree $tree, PendingChangesService $pcs, array $params): ResponseInterface
    {
        $a = $this->resolveRecord($tree, (string) ($params['xref'] ?? ''));
        $b = $this->resolveRecord($tree, (string) ($params['other_xref'] ?? ''));
        if ($a === null || $b === null) {
            return $this->json(['ok' => false, 'error' => 'xref and other_xref must both exist']);
        }

        $a_xref = $a->xref();
        $b_xref = $b->xref();

        $this->stripLink($a, $b_xref, $pcs);
        $b = $this->resolveRecord($tree, $b_xref);
        if ($b !== null) {
            $this->stripLink($b, $a_xref, $pcs);
        }

        return $this->json(['ok' => true, 'unlinked' => [$a_xref, $b_xref]]);
    }

    /**
     * Remove all level-1 links to $xref from a record's GEDCOM, then save.
     * (webtrees 2.2.6 removed GedcomRecord::removeLinks(); this mirrors the
     * regex DeleteRecord uses.)
     */
    private function stripLink(GedcomRecord $record, string $xref, PendingChangesService $pcs): void
    {
        $old = $record->gedcom();
        $new = preg_replace('/\n1 [A-Z_]+ @' . preg_quote($xref, '/') . '@(\n[2-9].*)*/', '', $old);

        if ($new !== null && $new !== $old) {
            $record->updateRecord($new, false);
            $pcs->acceptRecord($record);
        }
    }

    /**
     * @param array<string,mixed> $params
     */
    private function userUpdate(UserService $user_service, Tree $tree, array $params): ResponseInterface
    {
        $user = $user_service->find((int) ($params['user_id'] ?? 0));
        if ($user === null) {
            return $this->json(['ok' => false, 'error' => 'user not found']);
        }

        $changed = [];
        if (isset($params['real_name'])) { $user->setRealName($this->cleanLine((string) $params['real_name'])); $changed[] = 'real_name'; }
        if (isset($params['email']))     { $user->setEmail($this->cleanLine((string) $params['email'])); $changed[] = 'email'; }
        if (isset($params['user_name'])) { $user->setUserName($this->cleanLine((string) $params['user_name'])); $changed[] = 'user_name'; }
        if (isset($params['password']))  { $user->setPassword((string) $params['password']); $changed[] = 'password'; }
        if (isset($params['verified']))          { $user->setPreference('verified', ((string) $params['verified']) === '1' ? '1' : '0'); $changed[] = 'verified'; }
        if (isset($params['verified_by_admin'])) { $user->setPreference('verified_by_admin', ((string) $params['verified_by_admin']) === '1' ? '1' : '0'); $changed[] = 'verified_by_admin'; }
        if (isset($params['role'])) {
            $role = (string) $params['role'];
            if (in_array($role, ['none', 'access', 'edit', 'accept', 'admin'], true)) {
                $tree->setUserPreference($user, 'canedit', $role);
                $changed[] = 'role';
            }
        }
        if (isset($params['gedcomid'])) {
            $gid = $this->clean((string) $params['gedcomid']);
            $tree->setUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF, $gid);
            $tree->setUserPreference($user, 'rootid', $gid);
            $changed[] = 'gedcomid';
        }

        if ($changed === []) {
            return $this->json(['ok' => false, 'error' => 'no update fields provided']);
        }

        return $this->json(['ok' => true, 'updated' => $changed, 'user' => $this->userInfo($user_service->find($user->id()), $tree)]);
    }

    /**
     * List users. filter = all | unverified | unlinked.
     *
     * @param array<string,mixed> $params
     */
    private function userList(UserService $user_service, Tree $tree, array $params): ResponseInterface
    {
        $filter = (string) ($params['filter'] ?? 'all');
        $limit  = max(1, min(1000, (int) ($params['limit'] ?? 200)));

        $out = [];
        foreach ($user_service->all() as $u) {
            if ($u->id() <= 0) {
                continue;
            }
            $verified = $u->getPreference('verified');
            $admin_ok = $u->getPreference('verified_by_admin');
            $gid      = $tree->getUserPreference($u, UserInterface::PREF_TREE_ACCOUNT_XREF);

            if ($filter === 'unverified' && $verified === '1' && $admin_ok === '1') {
                continue;
            }
            if ($filter === 'unlinked' && $gid !== '') {
                continue;
            }

            $out[] = [
                'user_id'           => $u->id(),
                'user_name'         => $u->userName(),
                'real_name'         => $u->realName(),
                'email'             => $u->email(),
                'verified'          => $verified,
                'verified_by_admin' => $admin_ok,
                'gedcomid'          => $gid,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $this->json(['ok' => true, 'filter' => $filter, 'count' => count($out), 'users' => $out]);
    }

    // ─────────────────────────── pending-changes ops ────────────────────

    /**
     * Count pending changes for a tree.
     */
    private function pendingCount(Tree $tree): int
    {
        return DB::table('change')
            ->where('gedcom_id', '=', $tree->id())
            ->where('status', '=', 'pending')
            ->count();
    }

    /**
     * List every pending (unapproved) change for the tree. One entry per change
     * row, ordered oldest-first, with the record name, type, action, author and time.
     *
     * @param array<string,mixed> $params
     */
    private function pendingList(Tree $tree, array $params): ResponseInterface
    {
        $rows = DB::table('change')
            ->leftJoin('user', 'user.user_id', '=', 'change.user_id')
            ->where('change.gedcom_id', '=', $tree->id())
            ->where('change.status', '=', 'pending')
            ->orderBy('change.change_id')
            ->select([
                'change.change_id',
                'change.xref',
                'change.old_gedcom',
                'change.new_gedcom',
                'change.change_time',
                'user.user_name',
                'user.real_name',
            ])
            ->get();

        $out   = [];
        $xrefs = [];
        foreach ($rows as $r) {
            $old = (string) $r->old_gedcom;
            $new = (string) $r->new_gedcom;

            $action = $new === '' ? 'delete' : ($old === '' ? 'create' : 'update');

            preg_match('/^0 (?:@[^@]+@ )?(\w+)/', $old . "\n" . $new, $m);
            $type = $m[1] ?? '';

            $record = Registry::gedcomRecordFactory()->make($r->xref, $tree);
            $name   = $record !== null ? trim(strip_tags($record->fullName())) : '';

            $xrefs[$r->xref] = true;

            $out[] = [
                'change_id'   => (int) $r->change_id,
                'xref'        => (string) $r->xref,
                'type'        => $type,
                'action'      => $action,
                'name'        => $name,
                'user_name'   => (string) ($r->user_name ?? ''),
                'real_name'   => (string) ($r->real_name ?? ''),
                'change_time' => (string) $r->change_time,
            ];
        }

        return $this->json([
            'ok'      => true,
            'op'      => 'pending.list',
            'count'   => count($out),
            'records' => count($xrefs),
            'changes' => $out,
        ]);
    }

    /**
     * Approve EVERY pending change for the tree, in change order. Goes through
     * webtrees' PendingChangesService so the gedcom records + wt_name/wt_link
     * indexes stay consistent (same path as the admin "accept all" button).
     */
    private function pendingAcceptAll(Tree $tree, PendingChangesService $pcs): ResponseInterface
    {
        $before  = $this->pendingCount($tree);
        $records = DB::table('change')
            ->where('gedcom_id', '=', $tree->id())
            ->where('status', '=', 'pending')
            ->distinct()
            ->count('xref');

        $pcs->acceptTree($tree, PHP_INT_MAX);

        $remaining = $this->pendingCount($tree);

        return $this->json([
            'ok'             => true,
            'op'             => 'pending.acceptAll',
            'accepted'       => $before - $remaining,
            'records'        => $records,
            'pending_before' => $before,
            'remaining'      => $remaining,
        ]);
    }

    /**
     * Reject (discard) EVERY pending change for the tree.
     */
    private function pendingRejectAll(Tree $tree, PendingChangesService $pcs): ResponseInterface
    {
        $before = $this->pendingCount($tree);

        $pcs->rejectTree($tree);

        $remaining = $this->pendingCount($tree);

        return $this->json([
            'ok'        => true,
            'op'        => 'pending.rejectAll',
            'rejected'  => $before - $remaining,
            'remaining' => $remaining,
        ]);
    }

    /**
     * Approve all pending changes for one record (by xref).
     *
     * @param array<string,mixed> $params
     */
    private function pendingAccept(Tree $tree, PendingChangesService $pcs, array $params): ResponseInterface
    {
        $xref   = $this->clean((string) ($params['xref'] ?? ''));
        $record = $xref === '' ? null : Registry::gedcomRecordFactory()->make($xref, $tree);
        if ($record === null) {
            return $this->json(['ok' => false, 'error' => 'record not found: ' . $xref], StatusCodeInterface::STATUS_BAD_REQUEST);
        }

        $pcs->acceptRecord($record);

        return $this->json(['ok' => true, 'op' => 'pending.accept', 'xref' => $xref]);
    }

    /**
     * Reject all pending changes for one record (by xref).
     *
     * @param array<string,mixed> $params
     */
    private function pendingReject(Tree $tree, PendingChangesService $pcs, array $params): ResponseInterface
    {
        $xref   = $this->clean((string) ($params['xref'] ?? ''));
        $record = $xref === '' ? null : Registry::gedcomRecordFactory()->make($xref, $tree);
        if ($record === null) {
            return $this->json(['ok' => false, 'error' => 'record not found: ' . $xref], StatusCodeInterface::STATUS_BAD_REQUEST);
        }

        $pcs->rejectRecord($record);

        return $this->json(['ok' => true, 'op' => 'pending.reject', 'xref' => $xref]);
    }

    // ───────────────────────────── helpers ─────────────────────────────

    /**
     * Create + accept an individual from given_name/surname/sex/birth params.
     *
     * @param array<string,mixed> $params
     */
    private function makeIndividual(Tree $tree, PendingChangesService $pcs, array $params): ?Individual
    {
        $given   = $this->clean((string) ($params['given_name'] ?? $params['spouse_given'] ?? ''));
        $surname = $this->clean((string) ($params['surname'] ?? $params['spouse_surname'] ?? ''));
        $sex     = strtoupper($this->clean((string) ($params['sex'] ?? $params['spouse_sex'] ?? 'U')));

        if ($given === '' || $surname === '') {
            return null;
        }
        if (!in_array($sex, ['M', 'F', 'U'], true)) {
            $sex = 'U';
        }

        $gedcom = "0 @@ INDI\n1 NAME " . $given . ' /' . $surname . "/\n1 SEX " . $sex;

        $birth_date  = $this->clean((string) ($params['birth_date'] ?? ''));
        $birth_place = $this->clean((string) ($params['birth_place'] ?? ''));
        if ($birth_date !== '' || $birth_place !== '') {
            $gedcom .= "\n1 BIRT";
            if ($birth_date !== '') {
                $gedcom .= "\n2 DATE " . $birth_date;
            }
            if ($birth_place !== '') {
                $gedcom .= "\n2 PLAC " . $birth_place;
            }
        }

        $indi = $tree->createIndividual($gedcom);
        $pcs->acceptRecord($indi);

        return $this->reload($indi);
    }

    private function reload(Individual $indi): Individual
    {
        return Registry::individualFactory()->make($indi->xref(), $indi->tree()) ?? $indi;
    }

    /**
     * Add one fact and accept it immediately (so multiple facts don't clobber
     * each other — each pending change is based on the latest accepted gedcom).
     */
    private function applyFact(Individual $indi, string $gedcom, PendingChangesService $pcs): Individual
    {
        $indi->createFact($gedcom, false);
        $pcs->acceptRecord($indi);

        return $this->reload($indi);
    }

    private function reloadFamily(Family $family, Tree $tree): Family
    {
        return Registry::familyFactory()->make($family->xref(), $tree) ?? $family;
    }

    /**
     * Resolve any record (individual, family, source, …) by xref.
     */
    private function resolveRecord(Tree $tree, string $xref): ?GedcomRecord
    {
        return Registry::gedcomRecordFactory()->make($this->clean($xref), $tree);
    }

    /**
     * Resolve a tree by name. Called AFTER elevation (run-as admin), so
     * TreeService::all() returns the private tree too. Using the service avoids
     * depending on the Tree constructor signature (which changed in 2.2.6).
     */
    private function resolveTree(string $name): ?Tree
    {
        $name = $this->clean($name);

        return Registry::container()->get(\Fisharebest\Webtrees\Services\TreeService::class)
            ->all()
            ->first(static fn (Tree $t): bool => $t->name() === $name);
    }

    /**
     * @return array<string,mixed>
     */
    private function familyInfo(Family $family): array
    {
        $husband = $family->husband();
        $wife    = $family->wife();

        return [
            'xref'     => $family->xref(),
            'husband'  => $husband === null ? null : ['xref' => $husband->xref(), 'name' => strip_tags($husband->fullName())],
            'wife'     => $wife === null ? null : ['xref' => $wife->xref(), 'name' => strip_tags($wife->fullName())],
            'children' => $family->children()
                ->map(static fn (Individual $c): array => ['xref' => $c->xref(), 'name' => strip_tags($c->fullName())])
                ->values()
                ->all(),
        ];
    }

    /**
     * Build a "1 TAG [value] / 2 DATE / 2 PLAC" fact from tag/value/date/place params.
     * Returns null if the tag is missing/invalid.
     *
     * @param array<string,mixed> $params
     */
    private function factGedcom(array $params): ?string
    {
        $tag = strtoupper($this->clean((string) ($params['tag'] ?? '')));
        if (preg_match('/^[A-Z_]{2,10}$/', $tag) !== 1) {
            return null;
        }
        $value = $this->clean((string) ($params['value'] ?? ''));
        $date  = $this->clean((string) ($params['date'] ?? ''));
        $place = $this->clean((string) ($params['place'] ?? ''));

        $gedcom = '1 ' . $tag;
        if ($value !== '') {
            $gedcom .= ' ' . $value;
        }
        if ($date !== '') {
            $gedcom .= "\n2 DATE " . $date;
        }
        if ($place !== '') {
            $gedcom .= "\n2 PLAC " . $place;
        }

        return $gedcom;
    }

    /**
     * Build a "1 TAG / 2 DATE / 2 PLAC" event block from scalar params, or '' if none.
     *
     * @param array<string,mixed> $params
     */
    private function eventGedcom(string $tag, array $params, string $date_key, string $place_key): string
    {
        $date  = $this->clean((string) ($params[$date_key] ?? ''));
        $place = $this->clean((string) ($params[$place_key] ?? ''));
        if ($date === '' && $place === '') {
            return '';
        }
        $gedcom = '1 ' . $tag;
        if ($date !== '') {
            $gedcom .= "\n2 DATE " . $date;
        }
        if ($place !== '') {
            $gedcom .= "\n2 PLAC " . $place;
        }

        return $gedcom;
    }

    /**
     * @return array<string,mixed>
     */
    private function userInfo(UserInterface $user, Tree $tree): array
    {
        return [
            'user_id'           => $user->id(),
            'user_name'         => $user->userName(),
            'real_name'         => $user->realName(),
            'email'             => $user->email(),
            'verified'          => $user->getPreference('verified'),
            'verified_by_admin' => $user->getPreference('verified_by_admin'),
            'canedit'           => $tree->getUserPreference($user, 'canedit'),
            'gedcomid'          => $tree->getUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function indiInfo(Individual $indi): array
    {
        $spouseFamilies = $indi->spouseFamilies()->map(static fn (Family $f): string => $f->xref())->all();
        $childFamilies  = $indi->childFamilies()->map(static fn (Family $f): string => $f->xref())->all();

        return [
            'xref'            => $indi->xref(),
            'name'            => strip_tags($indi->fullName()),
            'sex'             => $indi->sex(),
            // 'families' kept for backward compatibility = spouse families only.
            // Use 'spouse_families' / 'child_families' for an unambiguous split.
            'families'        => $spouseFamilies,
            'spouse_families' => $spouseFamilies,
            'child_families'  => $childFamilies,
        ];
    }

    /**
     * Strip characters that could break GEDCOM line structure / XREF pointers.
     * Use for anything written into GEDCOM (names, places, facts).
     */
    private function clean(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\t", '@'], ' ', $value));
    }

    /**
     * Strip line breaks only (keeps '@'). Use for user fields like email.
     */
    private function cleanLine(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\t"], ' ', $value));
    }

    /**
     * @param array<string,mixed> $data
     */
    private function json(array $data, int $status = StatusCodeInterface::STATUS_OK): ResponseInterface
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return response($body, $status)->withHeader('content-type', 'application/json; charset=UTF-8');
    }

    /**
     * Merge query params and JSON/form body into one array.
     *
     * @return array<string,mixed>
     */
    private function params(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();

        $body = [];
        $raw  = (string) $request->getBody();
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }
        if ($body === []) {
            $parsed = $request->getParsedBody();
            if (is_array($parsed)) {
                $body = $parsed;
            }
        }

        return array_merge($query, $body);
    }

    // ───────────────────────────── forum ops ─────────────────────────────

    /**
     * Allowed attachment extensions. Mirrors ForumModule::ALLOWED_ATTACHMENT_EXTS;
     * duplicated here so the api module has no hard dependency on the forum
     * module being installed at any particular version. Keep in sync.
     */
    private const FORUM_ALLOWED_EXTS = [
        'pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'heic',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'txt', 'csv', 'rtf', 'odt', 'ods', 'odp',
        'zip', 'mp3', 'mp4', 'm4a', 'mov', 'wav',
    ];

    /** 10 MB cap per attachment (mirrors ForumModule). */
    private const FORUM_MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;

    /** Title length cap (mirrors meran_forum_topics.title VARCHAR(150)). */
    private const FORUM_TITLE_MAX = 150;

    /**
     * The forum module's classes are not on the composer autoloader (they live
     * in modules_v4/forum/, namespace Fisharebest\Webtrees\). require_once them
     * lazily so the api module still loads cleanly if the forum module is absent.
     */
    private function requireForum(): void
    {
        $base = __DIR__ . '/../forum/';
        if (!is_dir($base)) {
            throw new \RuntimeException('forum module not installed at modules_v4/forum/');
        }
        foreach (['ForumTopicCreator.php', 'ForumOutbox.php', 'ForumMailer.php', 'ForumModule.php'] as $f) {
            $p = $base . $f;
            if (!is_file($p)) {
                throw new \RuntimeException('forum module file missing: ' . $f);
            }
            require_once $p;
        }
    }

    /**
     * Distinct categories present in the forum. Useful for the caller to
     * validate `category` before posting (the forum schema doesn't constrain
     * it, but writing a typo'd category produces an orphan bucket).
     */
    private function forumListCategories(): ResponseInterface
    {
        $this->requireForum();
        // Raw SQL: forum tables are NOT under the webtrees wt_ prefix,
        // so DB::table() would mis-prefix them. DB::select() runs verbatim.
        $rows = DB::select('SELECT forum, COUNT(*) AS topics FROM meran_forum_topics GROUP BY forum ORDER BY topics DESC');
        $cats = [];
        foreach ($rows as $r) {
            $cats[] = ['name' => (string) $r->forum, 'topics' => (int) $r->topics];
        }
        return $this->json(['ok' => true, 'op' => 'forum.listCategories', 'categories' => $cats]);
    }

    /**
     * Create a topic. Optionally broadcast it to ~all family members.
     *
     * Required: title, body, category, author (XREF, e.g. "I1092").
     * Optional:
     *   broadcast        — "0" to skip the broadcast (default "1")
     *   attachment[]     — multipart-uploaded files (same field name the forum form uses)
     *   attachment_paths — JSON array (or comma-separated) of absolute paths to files
     *                      already placed on the server (FTP/SSH); they are MOVED
     *                      into the canonical forum_attachments/<sub>/<name> location.
     *
     * Returns: { ok, topic_id, url, broadcast: {enqueued, sent, failed, remaining} | null }
     *
     * @param array<string,mixed> $params
     */
    private function forumPostTopic(Tree $tree, ServerRequestInterface $request, array $params): ResponseInterface
    {
        $this->requireForum();

        $title    = $this->cleanLine((string) ($params['title'] ?? ''));
        $body     = $this->clean((string) ($params['body'] ?? ''));
        $category = $this->cleanLine((string) ($params['category'] ?? ''));
        $author   = $this->cleanLine((string) ($params['author'] ?? $params['authorXref'] ?? ''));
        $broadcast = !isset($params['broadcast']) || (string) $params['broadcast'] === '1' || $params['broadcast'] === true;

        if ($title === '' || $body === '' || $category === '' || $author === '') {
            return $this->json([
                'ok' => false,
                'error' => 'forum.postTopic requires title, body, category, author',
            ], StatusCodeInterface::STATUS_BAD_REQUEST);
        }
        if (mb_strlen($title) > self::FORUM_TITLE_MAX) {
            return $this->json([
                'ok' => false,
                'error' => 'title too long (max ' . self::FORUM_TITLE_MAX . ' chars)',
            ], StatusCodeInterface::STATUS_BAD_REQUEST);
        }

        $creator = new \Fisharebest\Webtrees\ForumTopicCreator();
        if (!$creator->xrefExistsInUsers($author)) {
            return $this->json([
                'ok' => false,
                'error' => 'author XREF "' . $author . '" does not match any family member',
            ], StatusCodeInterface::STATUS_BAD_REQUEST);
        }

        // Attachments BEFORE topic create, so a fatal mid-upload doesn't leave an empty topic.
        try {
            $attachments = $this->forumIngestAttachments($request, $params);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => 'attachment ingest failed: ' . $e->getMessage()], StatusCodeInterface::STATUS_BAD_REQUEST);
        }
        $attachment_urls  = array_column($attachments, 'url');
        $attachment_paths = array_column($attachments, 'path');
        $body_for_topic   = $body;
        foreach ($attachment_urls as $u) {
            $body_for_topic .= "\n\n———\nAttachment: " . $u;
        }

        try {
            $topic_id = $creator->createTopic($title, $body_for_topic, $category, $author);
        } catch (\Throwable $e) {
            foreach ($attachment_paths as $p) {
                if (is_string($p) && is_file($p)) {
                    @unlink($p);
                    @rmdir(dirname($p));
                }
            }
            return $this->json(['ok' => false, 'error' => 'createTopic failed: ' . $e->getMessage()], StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR);
        }

        $url = route(\Fisharebest\Webtrees\Module\ForumModule::class . ':viewTopic', [
            'tree' => $tree->name(),
            'topicId' => $topic_id,
        ]);

        $bcast_result = null;
        if ($broadcast) {
            try {
                (new \Fisharebest\Webtrees\ForumMailer($tree))
                    ->broadcastTopic($topic_id, $category, $title, $body_for_topic, $author, $attachment_urls, $attachment_paths);
                // Counts from outbox.
                $bcast_result = $this->forumOutboxCounts($topic_id);
            } catch (\Throwable $e) {
                $bcast_result = ['error' => $e->getMessage()];
            }
        }

        return $this->json([
            'ok' => true,
            'op' => 'forum.postTopic',
            'topic_id' => $topic_id,
            'url' => $url,
            'author' => $author,
            'category' => $category,
            'attachments' => count($attachments),
            'broadcast' => $bcast_result,
        ]);
    }

    /**
     * Append a comment to an existing topic. Optionally notifies prior
     * participants (mirrors the in-app reply flow).
     *
     * Required: topic_id, text, author (XREF).
     * Optional: notify (default "1"), attachment[], attachment_paths.
     *
     * @param array<string,mixed> $params
     */
    private function forumAddComment(Tree $tree, ServerRequestInterface $request, array $params): ResponseInterface
    {
        $this->requireForum();

        $topic_id = (int) ($params['topic_id'] ?? $params['topicId'] ?? 0);
        $text     = $this->clean((string) ($params['text'] ?? ''));
        $author   = $this->cleanLine((string) ($params['author'] ?? $params['authorXref'] ?? ''));
        $notify   = !isset($params['notify']) || (string) $params['notify'] === '1' || $params['notify'] === true;

        if ($topic_id <= 0 || $text === '' || $author === '') {
            return $this->json([
                'ok' => false,
                'error' => 'forum.addComment requires topic_id, text, author',
            ], StatusCodeInterface::STATUS_BAD_REQUEST);
        }

        $creator = new \Fisharebest\Webtrees\ForumTopicCreator();
        if (!$creator->xrefExistsInUsers($author)) {
            return $this->json([
                'ok' => false,
                'error' => 'author XREF "' . $author . '" does not match any family member',
            ], StatusCodeInterface::STATUS_BAD_REQUEST);
        }

        // Verify topic exists. Raw SQL because forum tables skip the wt_ prefix.
        $exists = DB::select('SELECT 1 FROM meran_forum_topics WHERE topic_id = ? LIMIT 1', [$topic_id]);
        if (empty($exists)) {
            return $this->json(['ok' => false, 'error' => 'topic ' . $topic_id . ' not found'], StatusCodeInterface::STATUS_NOT_FOUND);
        }

        try {
            $attachments = $this->forumIngestAttachments($request, $params);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => 'attachment ingest failed: ' . $e->getMessage()], StatusCodeInterface::STATUS_BAD_REQUEST);
        }
        $attachment_urls = array_column($attachments, 'url');
        $body_for_comment = $text;
        foreach ($attachment_urls as $u) {
            $body_for_comment .= "\n\n———\nAttachment: " . $u;
        }

        try {
            $message_id = $creator->addComment($topic_id, $body_for_comment, $author);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => 'addComment failed: ' . $e->getMessage()], StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR);
        }

        $url = route(\Fisharebest\Webtrees\Module\ForumModule::class . ':viewTopic', [
            'tree' => $tree->name(),
            'topicId' => $topic_id,
        ]) . '#m' . $message_id;

        $notify_result = null;
        if ($notify) {
            try {
                (new \Fisharebest\Webtrees\ForumMailer($tree))
                    ->notifyReply($topic_id, $body_for_comment, $author);
                $notify_result = 'sent';
            } catch (\Throwable $e) {
                $notify_result = ['error' => $e->getMessage()];
            }
        }

        return $this->json([
            'ok' => true,
            'op' => 'forum.addComment',
            'topic_id' => $topic_id,
            'message_id' => $message_id,
            'url' => $url,
            'attachments' => count($attachments),
            'notify' => $notify_result,
        ]);
    }

    /**
     * Delete a topic and ALL of its comments. Admin-only by virtue of the API
     * token; no per-user ownership check.
     *
     * @param array<string,mixed> $params
     */
    private function forumDeleteTopic(array $params): ResponseInterface
    {
        $this->requireForum();
        $topic_id = (int) ($params['topic_id'] ?? $params['topicId'] ?? 0);
        if ($topic_id <= 0) {
            return $this->json(['ok' => false, 'error' => 'topic_id required'], StatusCodeInterface::STATUS_BAD_REQUEST);
        }

        // Raw SQL: forum tables skip the wt_ prefix, so the query-builder would mis-prefix.
        $row = DB::select('SELECT COUNT(*) AS c FROM meran_forum_comments WHERE topic_id = ?', [$topic_id]);
        $comments = (int) ($row[0]->c ?? 0);
        DB::statement('DELETE FROM meran_forum_comments WHERE topic_id = ?', [$topic_id]);
        DB::statement('DELETE FROM meran_forum_outbox WHERE topic_id = ?', [$topic_id]);
        $topic_deleted = DB::affectingStatement('DELETE FROM meran_forum_topics WHERE topic_id = ?', [$topic_id]);

        return $this->json([
            'ok' => true,
            'op' => 'forum.deleteTopic',
            'topic_id' => $topic_id,
            'topic_deleted' => (int) $topic_deleted,
            'comments_deleted' => $comments,
        ]);
    }

    /**
     * Delete a single comment. If it was the last one in its topic, the topic
     * is removed too (mirrors ForumTopicCreator::deleteComment).
     *
     * @param array<string,mixed> $params
     */
    private function forumDeleteComment(array $params): ResponseInterface
    {
        $this->requireForum();
        $message_id = (int) ($params['message_id'] ?? $params['messageId'] ?? 0);
        if ($message_id <= 0) {
            return $this->json(['ok' => false, 'error' => 'message_id required'], StatusCodeInterface::STATUS_BAD_REQUEST);
        }
        $res = (new \Fisharebest\Webtrees\ForumTopicCreator())->deleteComment($message_id);
        return $this->json([
            'ok' => true,
            'op' => 'forum.deleteComment',
            'message_id' => $message_id,
            'topic_id' => $res['topic_id'] ?? 0,
            'topic_deleted' => (bool) ($res['topic_deleted'] ?? false),
        ]);
    }

    /**
     * Ingest attachments from EITHER:
     *   (a) multipart upload — field name "attachment" or "attachment[]"
     *       (same name the forum form uses; one file or many);
     *   (b) `attachment_paths` param — JSON-array or comma-separated list of
     *       absolute server-side paths; the API moves them into the canonical
     *       forum_attachments/<sub>/<name> location.
     *
     * Files exceeding the per-file size cap or with disallowed extensions are
     * SKIPPED silently — the topic still goes through. (Matches the forum
     * form's FlashMessage-warn-and-continue behaviour, except we have no Flash
     * here.) If you want strict failure, set strict=1 in params.
     *
     * Returns: list of ['url'=>string, 'path'=>string], same shape as
     * ForumModule::storeAttachments — directly consumable by ForumMailer.
     *
     * @param array<string,mixed> $params
     * @return list<array{url:string,path:string}>
     */
    private function forumIngestAttachments(ServerRequestInterface $request, array $params): array
    {
        $out = [];
        $strict = isset($params['strict']) && ((string) $params['strict'] === '1' || $params['strict'] === true);

        // Where attachments live (relative to docroot/data/forum_attachments/).
        $dataRoot = realpath(__DIR__ . '/../../data');
        if ($dataRoot === false) {
            throw new \RuntimeException('data/ not resolvable from api module');
        }

        // (a) multipart uploads
        $files = $request->getUploadedFiles();
        $raw   = $files['attachment'] ?? $files['datei'] ?? null;
        if ($raw !== null) {
            $list = is_array($raw) ? $raw : [$raw];
            foreach ($list as $f) {
                if (!$f instanceof \Psr\Http\Message\UploadedFileInterface) continue;
                if ($f->getError() === UPLOAD_ERR_NO_FILE) continue;
                $row = $this->forumStoreOneUploaded($f, $dataRoot, $strict);
                if ($row !== null) $out[] = $row;
            }
        }

        // (b) server-side paths
        $paths_raw = $params['attachment_paths'] ?? $params['attachment_path'] ?? null;
        if ($paths_raw !== null) {
            $paths = [];
            if (is_array($paths_raw)) {
                $paths = $paths_raw;
            } elseif (is_string($paths_raw) && $paths_raw !== '') {
                // accept either JSON or comma-separated
                $j = json_decode($paths_raw, true);
                $paths = is_array($j) ? $j : array_map('trim', explode(',', $paths_raw));
            }
            foreach ($paths as $srcPath) {
                $row = $this->forumStoreOnePath((string) $srcPath, $dataRoot, $strict);
                if ($row !== null) $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @return array{url:string,path:string}|null
     */
    private function forumStoreOneUploaded(\Psr\Http\Message\UploadedFileInterface $file, string $dataRoot, bool $strict): ?array
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            if ($strict) throw new \RuntimeException('upload error ' . $file->getError());
            return null;
        }
        if ($file->getSize() > self::FORUM_MAX_ATTACHMENT_BYTES) {
            if ($strict) throw new \RuntimeException('attachment exceeds size cap');
            return null;
        }
        $name = $this->sanitiseAttachmentName((string) $file->getClientFilename());
        if ($name === null) {
            if ($strict) throw new \RuntimeException('invalid attachment name/extension');
            return null;
        }
        [$sub, $dir, $target] = $this->forumAttachmentTarget($dataRoot, $name);
        if ($target === null) {
            if ($strict) throw new \RuntimeException('failed to allocate attachment dir');
            return null;
        }
        $file->moveTo($target);
        return [
            'url'  => route(\Fisharebest\Webtrees\Module\ForumModule::class . ':attachmentPublic', ['sub' => $sub, 'name' => $name]),
            'path' => $target,
        ];
    }

    /**
     * @return array{url:string,path:string}|null
     */
    private function forumStoreOnePath(string $srcPath, string $dataRoot, bool $strict): ?array
    {
        $real = realpath($srcPath);
        if ($real === false || !is_file($real)) {
            if ($strict) throw new \RuntimeException('source file not found: ' . $srcPath);
            return null;
        }
        if (filesize($real) > self::FORUM_MAX_ATTACHMENT_BYTES) {
            if ($strict) throw new \RuntimeException('attachment exceeds size cap');
            return null;
        }
        $name = $this->sanitiseAttachmentName(basename($real));
        if ($name === null) {
            if ($strict) throw new \RuntimeException('invalid attachment name/extension');
            return null;
        }
        [$sub, $dir, $target] = $this->forumAttachmentTarget($dataRoot, $name);
        if ($target === null) {
            if ($strict) throw new \RuntimeException('failed to allocate attachment dir');
            return null;
        }
        // Prefer rename (atomic on same filesystem); fall back to copy.
        if (!@rename($real, $target)) {
            if (!@copy($real, $target)) {
                if ($strict) throw new \RuntimeException('failed to place attachment in target dir');
                return null;
            }
        }
        return [
            'url'  => route(\Fisharebest\Webtrees\Module\ForumModule::class . ':attachmentPublic', ['sub' => $sub, 'name' => $name]),
            'path' => $target,
        ];
    }

    /**
     * Returns sanitised basename or null if rejected.
     */
    private function sanitiseAttachmentName(string $raw): ?string
    {
        $name = basename($raw);
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
        if ($name === null || $name === '' || $name[0] === '.') return null;
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, self::FORUM_ALLOWED_EXTS, true)) return null;
        return $name;
    }

    /**
     * @return array{0:string,1:string,2:string|null}  [sub, dir, target] — target null on mkdir failure.
     */
    private function forumAttachmentTarget(string $dataRoot, string $name): array
    {
        $sub = bin2hex(random_bytes(8));
        $dir = $dataRoot . '/forum_attachments/' . $sub;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return [$sub, $dir, null];
        }
        return [$sub, $dir, $dir . '/' . $name];
    }

    /**
     * @return array{enqueued:int,sent:int,failed:int,remaining:int}
     */
    private function forumOutboxCounts(int $topic_id): array
    {
        // Fresh mysqli — the request's Eloquent connection holds a REPEATABLE READ
        // snapshot from before ForumMailer's own mysqli wrote+committed the outbox
        // rows, so DB::select() reads zeros. A new connection gets a current snapshot.
        $c = parse_ini_file(__DIR__ . '/../../data/config.ini.php');
        $by = ['queued' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0];
        try {
            $db = new \mysqli($c['dbhost'], $c['dbuser'], $c['dbpass'], $c['dbname'], (int) ($c['dbport'] ?? 3306));
            if ($db->connect_errno) {
                return ['enqueued' => 0, 'sent' => 0, 'failed' => 0, 'remaining' => 0, 'note' => 'count connect failed'];
            }
            $stmt = $db->prepare('SELECT status, COUNT(*) AS c FROM meran_forum_outbox WHERE topic_id = ? GROUP BY status');
            $stmt->bind_param('i', $topic_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $by[(string) $row['status']] = (int) $row['c'];
            }
            $stmt->close();
            $db->close();
        } catch (\Throwable $e) {
            return ['enqueued' => 0, 'sent' => 0, 'failed' => 0, 'remaining' => 0, 'note' => 'count error: ' . $e->getMessage()];
        }
        return [
            'enqueued'  => array_sum($by),
            'sent'      => $by['sent'],
            'failed'    => $by['failed'],
            'remaining' => $by['queued'] + $by['sending'],
        ];
    }
}
