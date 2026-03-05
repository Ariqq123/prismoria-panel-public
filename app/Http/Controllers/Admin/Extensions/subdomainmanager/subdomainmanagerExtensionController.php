<?php

namespace Pterodactyl\Http\Controllers\Admin\Extensions\subdomainmanager;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\View\View;
use Prologue\Alerts\AlertsMessageBag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Contracts\View\Factory;
use Pterodactyl\Http\Controllers\Controller;
use Illuminate\Contracts\Foundation\Application;
use Pterodactyl\Exceptions\Model\DataValidationException;
use Pterodactyl\Services\Helpers\BlueprintExtensionLibrary;
use Pterodactyl\Exceptions\Repository\RecordNotFoundException;
use Pterodactyl\Contracts\Repository\SettingsRepositoryInterface;

class subdomainmanagerExtensionController extends Controller
{
    /**
     * @param AlertsMessageBag $alert
     * @param SettingsRepositoryInterface $settings
     */
    public function __construct(private AlertsMessageBag $alert, private SettingsRepositoryInterface $settings, private BlueprintExtensionLibrary $blueprint)
    {
    }

    /**
     * @return View|Factory|Application
     */
    public function index(): View|Factory|Application
    {
        $domains = DB::table('subdomain_manager_domains')->get();
        $subdomains = DB::table('subdomain_manager_subdomains')->get();

        $domains = json_decode(json_encode($domains), true);
        $subdomains = json_decode(json_encode($subdomains), true);

        foreach ($subdomains as $key => $subdomain) {
            $serverSource = (string) ($subdomain['server_source'] ?? 'local');
            $serverIdentifier = (string) ($subdomain['server_identifier'] ?? '');

            if ($serverSource === 'external') {
                $subdomains[$key]['server'] = (object) [
                    'id' => 0,
                    'uuidShort' => $serverIdentifier,
                    'name' => $serverIdentifier !== '' ? 'External: ' . $serverIdentifier : 'External server',
                ];
            } else {
                $serverData = DB::table('servers')->select(['id', 'uuidShort', 'name'])->where('id', '=', $subdomain['server_id'])->get();
                if (count($serverData) < 1) {
                    $subdomains[$key]['server'] = (object) [
                        'id' => 0,
                        'uuidShort' => '',
                        'name' => 'Not found',
                    ];
                } else {
                    $subdomains[$key]['server'] = $serverData[0];
                }
            }

            if (!isset($subdomains[$key]['server'])) {
                $subdomains[$key]['server'] = (object) [
                    'id' => 0,
                    'uuidShort' => '',
                    'name' => 'Not found',
                ];
            }

            $subdomains[$key]['domain'] = [
                'domain' => 'Not found',
            ];

            foreach ($domains as $domain) {
                if ($domain['id'] == $subdomain['domain_id']) {
                    $subdomains[$key]['domain'] = $domain;
                }
            }
        }

        return view('blueprint.extensions.subdomainmanager.index', [
            'settings' => [
                'cf_api_token' => $this->settings->get('settings::subdomain::cf_api_token', ''),
                'cf_email' => $this->settings->get('settings::subdomain::cf_email', ''),
                'cf_api_key' => $this->settings->get('settings::subdomain::cf_api_key', ''),
                'min3_api_key' => $this->settings->get('settings::subdomain::min3_api_key', ''),
                'max_subdomain' => $this->settings->get('settings::subdomain::max_subdomain', ''),
            ],
            'domains' => $domains,
            'subdomains' => $subdomains,
            'blueprint' => $this->blueprint,
        ]);
    }

    public function new(): View|Factory|Application
    {
        $eggs = DB::table('eggs')->get();

        return view('blueprint.extensions.subdomainmanager.new', [
            'eggs' => $eggs,
            'blueprint' => $this->blueprint,
        ]);
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     */
    public function create(Request $request): RedirectResponse
    {
        $this->validate($request, [
            'domain' => 'required|min:1|max:100',
            'provider' => 'required|in:cloudflare,min3',
            'egg_ids' => 'required',
        ]);

        $domain = trim(strip_tags($request->input('domain')));
        $provider = trim(strip_tags($request->input('provider', 'cloudflare')));
        $egg_ids = $request->input('egg_ids');
        $protocols = [];
        $types = [];

        foreach ($egg_ids as $egg_id) {
            $protocol = $request->input('protocol_for_' . $egg_id, '');
            $type = $request->input('protocol_type_for_' . $egg_id, '');
            $protocols[$egg_id] = $protocol;
            $types[$egg_id] = $type;
        }

        DB::table('subdomain_manager_domains')->insert([
            'domain' => $domain,
            'provider' => $provider,
            'egg_ids' => implode(',', $egg_ids),
            'protocol' => serialize($protocols),
            'protocol_types' => serialize($types),
        ]);

        $this->alert->success('You have successfully created new domain.')->flash();

        return redirect()->route('admin.subdomain');
    }

    /**
     * @param $id
     * @return Application|Factory|View|RedirectResponse
     */
    public function edit($id)
    {
        $id = (int) $id;

        $domain = DB::table('subdomain_manager_domains')->where('id', '=', $id)->get();
        if (count($domain) < 1) {
            $this->alert->danger('SubDomain not found!')->flash();

            return redirect()->route('admin.subdomain');
        }

        $eggs = DB::table('eggs')->get();

        return view('blueprint.extensions.subdomainmanager.edit', [
            'domain' => $domain[0],
            'eggs' => $eggs,
            'blueprint' => $this->blueprint,
        ]);
    }

    /**
     * @param Request $request
     * @param $id
     * @return RedirectResponse
     */
    public function update(Request $request, $id)
    {
        $id = (int) $id;

        $domain = DB::table('subdomain_manager_domains')->where('id', '=', $id)->get();
        if (count($domain) < 1) {
            $this->alert->danger('Domain not found.')->flash();

            return redirect()->route('admin.subdomain');
        }

        $this->validate($request, [
            'domain' => 'required|min:1|max:100',
            'provider' => 'required|in:cloudflare,min3',
            'egg_ids' => 'required',
        ]);

        $domain = trim(strip_tags($request->input('domain')));
        $provider = trim(strip_tags($request->input('provider', 'cloudflare')));
        $egg_ids = $request->input('egg_ids');
        $protocols = [];
        $types = [];

        foreach ($egg_ids as $egg_id) {
            $protocol = $request->input('protocol_for_' . $egg_id, '');
            $type = $request->input('protocol_type_for_' . $egg_id, '');
            $protocols[$egg_id] = $protocol;
            $types[$egg_id] = $type;
        }

        DB::table('subdomain_manager_domains')->where('id', '=', $id)->update([
            'domain' => $domain,
            'provider' => $provider,
            'egg_ids' => implode(',', $egg_ids),
            'protocol' => serialize($protocols),
            'protocol_types' => serialize($types),
        ]);

        $this->alert->success('You have successfully edited this domain.')->flash();

        return redirect()->route('admin.subdomain.edit', $id);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function delete(Request $request)
    {
        $domain_id = (int) $request->input('id', '');

        $domain = DB::table('subdomain_manager_domains')->where('id', '=', $domain_id)->get();
        if (count($domain) < 1) {
            return response()->json(['success' => false, 'error' => 'Domain not found.']);
        }

        DB::table('subdomain_manager_domains')->where('id', '=', $domain_id)->delete();
        DB::table('subdomain_manager_subdomains')->where('domain_id', '=', $domain_id)->delete();

        return response()->json(['success' => true]);
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     * @throws DataValidationException
     * @throws RecordNotFoundException
     */
    public function settings(Request $request)
    {
        $this->validate($request, [
            'cf_api_token' => 'nullable|max:255',
            'cf_email' => 'nullable|max:100',
            'cf_api_key' => 'nullable|max:100',
            'min3_api_key' => 'nullable|max:255',
            'max_subdomain' => 'required|min:0|integer',
        ]);

        $apiToken = trim((string) $request->input('cf_api_token'));
        $email = trim($request->input('cf_email'));
        $api_key = trim($request->input('cf_api_key'));
        $min3ApiKey = trim((string) $request->input('min3_api_key'));
        $max_subdomain = trim($request->input('max_subdomain'));

        if ($apiToken === '' && ($email === '' || $api_key === '') && $min3ApiKey === '') {
            $this->alert->danger('Configure Cloudflare credentials and/or Min3 API key.')->flash();

            return redirect()->route('admin.subdomain');
        }

        $this->settings->set('settings::subdomain::cf_api_token', $apiToken);
        $this->settings->set('settings::subdomain::cf_email', $email);
        $this->settings->set('settings::subdomain::cf_api_key', $api_key);
        $this->settings->set('settings::subdomain::min3_api_key', $min3ApiKey);
        $this->settings->set('settings::subdomain::max_subdomain', $max_subdomain);

        $this->alert->success('You have successfully updated settings.')->flash();

        return redirect()->route('admin.subdomain');
    }
}
