<?php
/**
 * Dynadot Module for Blesta (API3) – Data retention using service_fields (no ServiceMeta)
 */
class Dynadot extends RegistrarModule
{
    use Blesta\Core\Util\Common\Traits\Container;

    private static $defaultModuleView;

    public function __construct()
    {
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');
        Loader::loadComponents($this, ['Input', 'Record']);
        Language::loadLang('dynadot', null, dirname(__FILE__) . DS . 'language' . DS);
        Configure::load('dynadot', dirname(__FILE__) . DS . 'config' . DS);
        self::$defaultModuleView = 'components' . DS . 'modules' . DS . 'dynadot' . DS;
        Loader::loadModels($this, ['Packages', 'Services']);
    }

    // -------------------------------------------------------------------------
    // Module row management (unchanged)
    // -------------------------------------------------------------------------
    public function manageModule($module, array &$vars)
    {
        $this->view = new View('manage', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView(self::$defaultModuleView);
        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);
        $this->view->set('module', $module);
        return $this->view->fetch();
    }

    public function manageAddRow(array &$vars)
    {
        $this->view = new View('add_row', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView(self::$defaultModuleView);
        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);
        $this->view->set('vars', (object)$vars);
        return $this->view->fetch();
    }

    public function manageEditRow($module_row, array &$vars)
    {
        $this->view = new View('edit_row', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView(self::$defaultModuleView);
        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);
        if (empty($vars)) $vars = $module_row->meta;
        $this->view->set('vars', (object)$vars);
        return $this->view->fetch();
    }

    private function getRowRules(&$vars)
    {
        return ['api_key' => ['valid' => ['rule' => 'isEmpty', 'negate' => true, 'message' => Language::_('Dynadot.!error.api_key.valid', true)]]];
    }

    public function addModuleRow(array &$vars)
    {
        $this->Input->setRules($this->getRowRules($vars));
        if ($this->Input->validates($vars)) {
            $meta = [];
            foreach (['api_key'] as $key) {
                $meta[] = ['key' => $key, 'value' => $vars[$key], 'encrypted' => 1];
            }
            return $meta;
        }
    }

    public function editModuleRow($module_row, array &$vars)
    {
        $this->Input->setRules($this->getRowRules($vars));
        if ($this->Input->validates($vars)) {
            $meta = [];
            foreach (['api_key'] as $key) {
                $meta[] = ['key' => $key, 'value' => $vars[$key] ?? $module_row->meta->$key, 'encrypted' => 1];
            }
            return $meta;
        }
    }

    // -------------------------------------------------------------------------
    // Service actions (stubs – replace with your real implementations)
    // -------------------------------------------------------------------------
    public function addService($package, array $vars = null, $parent_package = null, $parent_service = null, $status = 'pending') {}
    public function editService($package, $service, array $vars = [], $parent_package = null, $parent_service = null) {}
    public function cancelService($package, $service, $parent_package = null, $parent_service = null)
    {
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->api_key);
        $domains = new DynadotDomains($api);
        $domain = $this->getServiceDomain($service);
        $response = $domains->setRenewOption($domain, 'donot');
        $this->processResponse($api, $response);
    }
    public function suspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        $this->cancelService($package, $service);
    }
    public function renewService($package, $service, $parent_package = null, $parent_service = null, $years = null)
    {
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->api_key);
        $domains = new DynadotDomains($api);
        $domain = $this->getServiceDomain($service);
        $duration = $years ?: $this->getServiceTerm($service);
        $response = $domains->renew($domain, $duration);
        $this->processResponse($api, $response);
    }

    // -------------------------------------------------------------------------
    // Package management (stubs)
    // -------------------------------------------------------------------------
    public function getPackageFields($vars = null) {}
    public function addPackage(array $vars = null) {}
    public function editPackage($package, array $vars = null) {}
    private function getPackageRules(array $vars) { return []; }

    // -------------------------------------------------------------------------
    // Helper methods
    // -------------------------------------------------------------------------
    public function getApi($key = null)
    {
        Loader::load(__DIR__ . DS . 'apis' . DS . 'dynadot_api.php');
        if (empty($key) && ($row = $this->getModuleRow())) $key = $row->meta->api_key;
        return new DynadotApi($key);
    }
    private function processResponse(DynadotApi $api, DynadotResponse $response)
    {
        $this->logRequest($api, $response);
        if ($api->httpcode != 200) $this->Input->setErrors(['errors' => ['HTTP ' . $api->httpcode]]);
        $errors = $response->errors();
        if (!empty($errors)) $this->Input->setErrors(['errors' => (array)$errors]);
    }
    private function logRequest(DynadotApi $api, DynadotResponse $response)
    {
        $last = $api->lastRequest();
        $url = substr($last['url'], 0, strpos($last['url'], '?') ?: strlen($last['url']));
        $this->log($url, serialize($last['args']), 'input', true);
        $this->log($url, serialize($response->raw()), 'output', $api->httpcode == 200);
    }
    public function getServiceDomain($service)
    {
        foreach ($service->fields as $field) if ($field->key == 'domain') return $field->value;
        return $this->getServiceName($service);
    }
    private function getServiceTerm($service)
    {
        foreach ($service->pricing as $pricing) if ($pricing->id == $service->pricing_id) return $pricing->term;
        return 1;
    }
    private function featureServiceEnabled($feature, $service)
    {
        foreach ($service->options as $opt) if ($opt->option_name == $feature) return true;
        return false;
    }
    public function getTlds($module_row_id = null) { return ['.com', '.net', '.org']; }
    public function getTldPricing($module_row_id = null) { return []; }
    public function getFilteredTldPricing($module_row_id = null, $filters = []) { return []; }

    // -------------------------------------------------------------------------
    // Admin Tabs (optional)
    // -------------------------------------------------------------------------
    public function getAdminServiceTabs($service) { return []; }
    public function tabAdminActions($package, $service, array $get = null, array $post = null, array $files = null) {}

    // -------------------------------------------------------------------------
    // Helper to store/fetch meta using service_fields table (no ServiceMeta)
    // -------------------------------------------------------------------------
    private function getServiceMeta($service_id, $key, $default = null)
    {
        $result = $this->Record->select('value')->from('service_fields')
            ->where('service_id', '=', $service_id)
            ->where('key', '=', $key)
            ->fetch();
        if ($result) {
            return $result->value;
        }
        return $default;
    }

    private function setServiceMeta($service_id, $key, $value)
    {
        $exists = $this->Record->from('service_fields')
            ->where('service_id', '=', $service_id)
            ->where('key', '=', $key)
            ->numResults();
        if ($exists) {
            $this->Record->where('service_id', '=', $service_id)
                ->where('key', '=', $key)
                ->update('service_fields', ['value' => $value]);
        } else {
            $this->Record->insert('service_fields', [
                'service_id' => $service_id,
                'key' => $key,
                'value' => $value,
                'encrypted' => 0
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // CLIENT TABS
    // -------------------------------------------------------------------------
    public function getClientServiceTabs($service)
    {
        return [
            'tabClientWhois' => ['name' => Language::_('Dynadot.tab_whois.title', true), 'icon' => 'fas fa-users'],
            'tabClientEmailForwarding' => ['name' => Language::_('Dynadot.tab_email_forwarding.title', true), 'icon' => 'fas fa-envelope'],
            'tabClientNameservers' => ['name' => Language::_('Dynadot.tab_nameservers.title', true), 'icon' => 'fas fa-server'],
            'tabClientHosts' => ['name' => Language::_('Dynadot.tab_hosts.title', true), 'icon' => 'fas fa-hdd'],
            'tabClientDnssec' => ['name' => Language::_('Dynadot.tab_dnssec.title', true), 'icon' => 'fas fa-globe-americas'],
            'tabClientDnsRecords' => ['name' => Language::_('Dynadot.tab_dnsrecord.title', true), 'icon' => 'fas fa-sitemap'],
            'tabClientSettings' => ['name' => Language::_('Dynadot.tab_settings.title', true), 'icon' => 'fas fa-cog'],
        ];
    }

    // ---------- WHOIS ----------
    public function tabClientWhois($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $this->view = new View('tab_client_whois', 'default');
        $this->view->setDefaultView(self::$defaultModuleView);
        Loader::loadHelpers($this, ['Form', 'Html']);

        $domain = $this->getServiceDomain($service);
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->api_key);
        $domains = new DynadotDomains($api);
        $vars = new stdClass();

        if ($post && isset($post['submit'])) {
            $contact = [];
            foreach (['Registrant', 'Admin', 'Tech', 'Billing'] as $type) {
                foreach (Configure::get('Dynadot.whois_fields') as $key => $field) {
                    $contact[$type][$field['rp']] = $post[$type . '_' . $key] ?? '';
                }
            }
            $response = $domains->setWhois($domain, $contact);
            $this->processResponse($api, $response);
            if (!$this->Input->errors()) {
                $this->setServiceMeta($service->id, 'whois_data', serialize($post));
                $this->setMessage('message', Language::_('Dynadot.!success.whois_updated', true));
            }
        }

        $stored = $this->getServiceMeta($service->id, 'whois_data');
        if ($stored) {
            $storedData = unserialize($stored);
            foreach (['Registrant', 'Admin', 'Tech', 'Billing'] as $type) {
                foreach (Configure::get('Dynadot.whois_fields') as $key => $field) {
                    $fieldName = $type . '_' . $key;
                    if (isset($storedData[$fieldName])) {
                        $vars->$fieldName = $storedData[$fieldName];
                    }
                }
            }
        }

        $this->view->set('vars', $vars);
        return $this->view->fetch();
    }

    // ---------- Email Forwarding ----------
    public function tabClientEmailForwarding($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $this->view = new View('tab_client_email_forwarding', 'default');
        $this->view->setDefaultView(self::$defaultModuleView);
        Loader::loadHelpers($this, ['Form', 'Html']);

        $domain = $this->getServiceDomain($service);
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->api_key);
        $domains = new DynadotDomains($api);
        $vars = new stdClass();

        if ($post) {
            if (!empty($post['delete_selected']) && !empty($post['delete'])) {
                foreach ($post['delete'] as $email) {
                    $response = $domains->deleteEmailForward($domain, $email);
                    $this->processResponse($api, $response);
                    if ($this->Input->errors()) break;
                }
                if (!$this->Input->errors()) {
                    $stored = $this->getServiceMeta($service->id, 'email_forwards');
                    $forwards = $stored ? unserialize($stored) : [];
                    foreach ($post['delete'] as $email) {
                        unset($forwards[$email]);
                    }
                    $this->setServiceMeta($service->id, 'email_forwards', serialize($forwards));
                    $this->setMessage('message', Language::_('Dynadot.!success.forwarders_deleted', true));
                }
            }
            if (!empty($post['add_forwarder']) && !empty($post['new_email']) && !empty($post['new_forward'])) {
                $response = $domains->addEmailForward($domain, $post['new_email'], $post['new_forward']);
                $this->processResponse($api, $response);
                if (!$this->Input->errors()) {
                    $stored = $this->getServiceMeta($service->id, 'email_forwards');
                    $forwards = $stored ? unserialize($stored) : [];
                    $forwards[$post['new_email']] = $post['new_forward'];
                    $this->setServiceMeta($service->id, 'email_forwards', serialize($forwards));
                    $this->setMessage('message', Language::_('Dynadot.!success.forwarder_added', true));
                }
            }
        }

        $stored = $this->getServiceMeta($service->id, 'email_forwards');
        $vars->forwards = [];
        if ($stored) {
            $forwards = unserialize($stored);
            foreach ($forwards as $email => $forwardTo) {
                $fwd = new stdClass();
                $fwd->Email = $email;
                $fwd->ForwardTo = $forwardTo;
                $vars->forwards[] = $fwd;
            }
        }

        $this->view->set('vars', $vars);
        $this->view->set('domain', $domain);
        return $this->view->fetch();
    }

    // ---------- Nameservers ----------
    public function tabClientNameservers($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $this->view = new View('tab_client_nameservers', 'default');
        $this->view->setDefaultView(self::$defaultModuleView);
        Loader::loadHelpers($this, ['Form', 'Html']);

        $domain = $this->getServiceDomain($service);
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->api_key);
        $domains = new DynadotDomains($api);
        $vars = new stdClass();

        if ($post && isset($post['submit'])) {
            $ns = array_filter($post['ns']);
            if (count($ns) < 2) {
                $this->Input->setErrors(['ns' => [Language::_('Dynadot.!error.ns_count', true)]]);
            } else {
                $response = $domains->setNameservers($domain, $ns);
                $this->processResponse($api, $response);
                if (!$this->Input->errors()) {
                    $this->setServiceMeta($service->id, 'nameservers', serialize($ns));
                    $this->setMessage('message', Language::_('Dynadot.!success.nameservers_updated', true));
                }
            }
        }

        $stored = $this->getServiceMeta($service->id, 'nameservers');
        if ($stored) {
            $vars->nameservers = unserialize($stored);
        } else {
            $vars->nameservers = [];
        }

        $this->view->set('vars', $vars);
        $this->view->set('domain', $domain);
        return $this->view->fetch();
    }

    // ---------- Hosts (Glue Records) ----------
    public function tabClientHosts($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $this->view = new View('tab_client_hosts', 'default');
        $this->view->setDefaultView(self::$defaultModuleView);
        Loader::loadHelpers($this, ['Form', 'Html']);

        $domain = $this->getServiceDomain($service);
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->api_key);
        $domains = new DynadotDomains($api);
        $vars = new stdClass();

        if ($post && isset($post['submit'])) {
            foreach ($post['host'] as $i => $hostname) {
                if (empty($hostname)) continue;
                $ips = array_filter($post['ip'][$i] ?? []);
                if (empty($ips)) {
                    $response = $domains->getNs($domain);
                    $this->processResponse($api, $response);
                    if (isset($response->response()->GetNsResponse->Ns)) {
                        foreach ((array)$response->response()->GetNsResponse->Ns as $host) {
                            if ($host->Host == $hostname) {
                                $domains->deleteNameserver($host->ServerId);
                                break;
                            }
                        }
                    }
                } else {
                    $response = $domains->registerNameserver($hostname, $ips[0]);
                    $this->processResponse($api, $response);
                }
            }
            if (!$this->Input->errors()) {
                $response = $domains->getNs($domain);
                $this->processResponse($api, $response);
                $result = $response->response();
                $hosts = [];
                if (isset($result->GetNsResponse->Ns)) {
                    $hosts = (array)$result->GetNsResponse->Ns;
                }
                $this->setServiceMeta($service->id, 'hosts_data', serialize($hosts));
                $this->setMessage('message', Language::_('Dynadot.!success.hosts_updated', true));
            }
        }

        $stored = $this->getServiceMeta($service->id, 'hosts_data');
        if ($stored) {
            $vars->hosts = unserialize($stored);
        } else {
            $vars->hosts = [];
        }

        $this->view->set('vars', $vars);
        $this->view->set('domain', $domain);
        return $this->view->fetch();
    }

    // ---------- DNSSEC ----------
    public function tabClientDnssec($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $this->view = new View('tab_client_dnssec', 'default');
        $this->view->setDefaultView(self::$defaultModuleView);
        Loader::loadHelpers($this, ['Form', 'Html']);

        $domain = $this->getServiceDomain($service);
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->api_key);
        $domains = new DynadotDomains($api);
        $vars = new stdClass();

        if ($post) {
            if (isset($post['delete_selected']) && !empty($post['delete_ds'])) {
                foreach ($post['delete_ds'] as $record_id) {
                    $response = $domains->deleteDnssec($domain, $record_id);
                    $this->processResponse($api, $response);
                    if ($this->Input->errors()) break;
                }
                if (!$this->Input->errors()) {
                    $response = $domains->getDnssec($domain);
                    $this->processResponse($api, $response);
                    $result = $response->response();
                    $records = [];
                    if (isset($result->GetDnssecResponse->DsRecord)) {
                        $records = (array)$result->GetDnssecResponse->DsRecord;
                    }
                    $this->setServiceMeta($service->id, 'dnssec_records', serialize($records));
                    $this->setMessage('message', Language::_('Dynadot.!success.dnssec_deleted', true));
                }
            }
            if (isset($post['add_ds']) && !empty($post['key_tag']) && !empty($post['algorithm']) && !empty($post['digest_type']) && !empty($post['digest'])) {
                $response = $domains->addDnssec($domain, $post['key_tag'], $post['algorithm'], $post['digest_type'], $post['digest']);
                $this->processResponse($api, $response);
                if (!$this->Input->errors()) {
                    $response = $domains->getDnssec($domain);
                    $this->processResponse($api, $response);
                    $result = $response->response();
                    $records = [];
                    if (isset($result->GetDnssecResponse->DsRecord)) {
                        $records = (array)$result->GetDnssecResponse->DsRecord;
                    }
                    $this->setServiceMeta($service->id, 'dnssec_records', serialize($records));
                    $this->setMessage('message', Language::_('Dynadot.!success.dnssec_added', true));
                }
            }
        }

        $stored = $this->getServiceMeta($service->id, 'dnssec_records');
        if ($stored) {
            $vars->records = unserialize($stored);
        } else {
            $vars->records = [];
        }

        $this->view->set('vars', $vars);
        $this->view->set('domain', $domain);
        return $this->view->fetch();
    }

    // ---------- DNS Records ----------
    public function tabClientDnsRecords($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $this->view = new View('tab_client_dnsrecords', 'default');
        $this->view->setDefaultView(self::$defaultModuleView);
        Loader::loadHelpers($this, ['Form', 'Html']);

        $domain = $this->getServiceDomain($service);
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->api_key);
        $domains = new DynadotDomains($api);
        $vars = new stdClass();

        if ($post) {
            if (isset($post['delete_selected']) && !empty($post['delete_record'])) {
                foreach ($post['delete_record'] as $record_id) {
                    $response = $domains->deleteDnsRecord($domain, $record_id);
                    $this->processResponse($api, $response);
                    if ($this->Input->errors()) break;
                }
                if (!$this->Input->errors()) {
                    $response = $domains->getDnsRecords($domain);
                    $this->processResponse($api, $response);
                    $result = $response->response();
                    $records = [];
                    if (isset($result->GetDnsResponse->Record)) {
                        $records = (array)$result->GetDnsResponse->Record;
                    }
                    $this->setServiceMeta($service->id, 'dns_records', serialize($records));
                    $this->setMessage('message', Language::_('Dynadot.!success.dns_records_deleted', true));
                }
            }
            if (isset($post['add_record']) && !empty($post['record_type']) && !empty($post['host']) && !empty($post['value'])) {
                $response = $domains->addDnsRecord($domain, $post['record_type'], $post['host'], $post['value'], $post['ttl'] ?? 3600);
                $this->processResponse($api, $response);
                if (!$this->Input->errors()) {
                    $response = $domains->getDnsRecords($domain);
                    $this->processResponse($api, $response);
                    $result = $response->response();
                    $records = [];
                    if (isset($result->GetDnsResponse->Record)) {
                        $records = (array)$result->GetDnsResponse->Record;
                    }
                    $this->setServiceMeta($service->id, 'dns_records', serialize($records));
                    $this->setMessage('message', Language::_('Dynadot.!success.dns_record_added', true));
                }
            }
        }

        $stored = $this->getServiceMeta($service->id, 'dns_records');
        if ($stored) {
            $vars->records = unserialize($stored);
        } else {
            $vars->records = [];
        }

        $this->view->set('vars', $vars);
        $this->view->set('domain', $domain);
        return $this->view->fetch();
    }

    // ---------- Settings ----------
    public function tabClientSettings($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $this->view = new View('tab_client_settings', 'default');
        $this->view->setDefaultView(self::$defaultModuleView);
        Loader::loadHelpers($this, ['Form', 'Html']);

        $domain = $this->getServiceDomain($service);
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->api_key);
        $domains = new DynadotDomains($api);
        $vars = new stdClass();

        if ($post && isset($post['submit'])) {
            if (isset($post['lock'])) {
                $response = $domains->setRegistrarLock($domain, $post['lock'] == 'yes');
                $this->processResponse($api, $response);
            }
            if (isset($post['epp_code'])) {
                $response = $domains->getTransferAuthCode($domain);
                $this->processResponse($api, $response);
                if (!$this->Input->errors()) {
                    $code = $response->response()->GetTransferAuthCodeResponse->AuthCode ?? '';
                    $this->setMessage('message', Language::_('Dynadot.!success.epp_code_sent', true) . ' ' . $code);
                }
            }
            if (isset($post['privacy'])) {
                $response = $domains->setPrivacy($domain, $post['privacy'] == 'yes');
                $this->processResponse($api, $response);
            }
            $info = $domains->getDomainInfo($domain);
            $this->processResponse($api, $info);
            $result = $info->response();
            $domainInfo = $result->DomainInfoResponse->DomainInfo ?? null;
            if ($domainInfo) {
                $vars->locked = ($domainInfo->Locked ?? 'no') == 'yes';
                $vars->privacy = ($domainInfo->Privacy ?? 'no') == 'full';
            }
        } else {
            $info = $domains->getDomainInfo($domain);
            $this->processResponse($api, $info);
            $result = $info->response();
            $domainInfo = $result->DomainInfoResponse->DomainInfo ?? null;
            if ($domainInfo) {
                $vars->locked = ($domainInfo->Locked ?? 'no') == 'yes';
                $vars->privacy = ($domainInfo->Privacy ?? 'no') == 'full';
            } else {
                $vars->locked = false;
                $vars->privacy = false;
            }
        }

        $this->view->set('vars', $vars);
        $this->view->set('domain', $domain);
        return $this->view->fetch();
    }
}
?>