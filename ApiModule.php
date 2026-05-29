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
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
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
                    return $this->userActivate($user_service, $tree, $params);

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
    private function userActivate(UserService $user_service, Tree $tree, array $params): ResponseInterface
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

        $user->setPreference('verified', '1');
        $user->setPreference('verified_by_admin', '1');
        $tree->setUserPreference($user, 'canedit', $role);

        if ($xref !== '') {
            $tree->setUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF, $xref);
            $tree->setUserPreference($user, 'rootid', $xref);
        }

        return $this->json(['ok' => true, 'user' => $this->userInfo($user, $tree)]);
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
        $a->removeLinks($b_xref, true);
        $pcs->acceptRecord($this->resolveRecord($tree, $a_xref) ?? $a);
        $b = $this->resolveRecord($tree, $b_xref) ?? $b;
        $b->removeLinks($a_xref, true);
        $pcs->acceptRecord($this->resolveRecord($tree, $b_xref) ?? $b);

        return $this->json(['ok' => true, 'unlinked' => [$a_xref, $b_xref]]);
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

    private function resolveTree(string $name): ?Tree
    {
        $row = DB::table('gedcom')->where('gedcom_name', '=', $name)->where('gedcom_id', '>', 0)->first();
        if ($row === null) {
            return null;
        }
        $title = DB::table('gedcom_setting')
            ->where('gedcom_id', '=', $row->gedcom_id)
            ->where('setting_name', '=', 'title')
            ->value('setting_value');

        return new Tree((int) $row->gedcom_id, $row->gedcom_name, (string) ($title ?? $row->gedcom_name));
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
        return [
            'xref'     => $indi->xref(),
            'name'     => strip_tags($indi->fullName()),
            'sex'      => $indi->sex(),
            'families' => $indi->spouseFamilies()->map(static fn (Family $f): string => $f->xref())->all(),
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
}
