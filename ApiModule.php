<?php

declare(strict_types=1);

namespace Fisharebest\Webtrees\Module;

use Fig\Http\Message\RequestMethodInterface;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Family;
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
        $user_name = $this->clean((string) ($params['user_name'] ?? ''));
        $real_name = $this->clean((string) ($params['real_name'] ?? ''));
        $email     = $this->clean((string) ($params['email'] ?? ''));
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
     */
    private function clean(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\t", '@'], ' ', $value));
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
