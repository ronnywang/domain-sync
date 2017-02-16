<?php

class SyncLib
{
    protected static $_config = null;

    public static function getConfig()
    {
        if (is_null(self::$_config)) {
            self::$_config = json_decode(file_get_contents(__DIR__ . '/config.json'));
        }
        return self::$_config;
    }

    public static function cloudflareRequest($url)
    {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'X-Auth-Email:' . getenv('cloudflare_mail'),
            'X-Auth-Key:' . getenv('cloudflare_key'),
        ));
        $content = curl_exec($curl);
        return $content;
    }

    public static function getCloudFlareConfig($root)
    {
        $config = self::getConfig();
        if (!property_exists($config, $root)) {
            throw new Exception("找不到 {$root} 設定");
        }

        $ret = new StdClass;
        foreach (array('cloudflare_key', 'cloudflare_mail') as $k) {
            if (property_exists($config->{$root}, $k)) {
                $ret->{$k} = $config->{$root}->{$k};
            } else {
                $ret->{$k} = $config->_global_->{$k};
            }
        }

        return $ret;
    }

    public static function getCloudFlareDomains($root)
    {
        $config = self::getConfig();
        if (!property_exists($config, $root)) {
            throw new Exception("找不到 {$root} 設定");
        }

        $url = sprintf("https://api.cloudflare.com/client/v4/zones/%s/dns_records?per_page=1000", $config->{$root}->cloudflare_zoneid);
        $domain_config = self::getCloudFlareConfig($root);
        putenv('cloudflare_key=' . $domain_config->cloudflare_key);
        putenv('cloudflare_mail=' . $domain_config->cloudflare_mail);
        $obj = json_decode(self::cloudflareRequest($url));
        if (!$obj->success) {
            print_r($obj);
            throw new Exception("get cloudflare domain failed");
        }

        return $obj->result;
    }

    public static function getGitHubDomains($root)
    {
        $config = self::getConfig();
        if (!property_exists($config, $root)) {
            throw new Exception("找不到 {$root} 設定");
        }

        $repo_path = self::getRepoPath($root);
        $obj = new StdClass;
        foreach (glob($repo_path . '/' . trim($config->{$root}->github_path, '/') . '/*.json') as $file) {
            if (is_link($file)) {
                continue;
            }
            $domain_obj = json_decode(file_get_contents($file));
            $domain_obj->filename = $file;
            foreach ($domain_obj->domains as $domain => $config) {
                if (property_exists($obj, $domain)) {
                    throw new Exception("{$domain} 同時存在於 " . $obj->{$domain}->filename . " 和 {$domain_obj->filename}");
                }
                $obj->{$domain} = $domain_obj;
            }
        }
        return $obj;
    }

    public static function checkDiff($cloudflare_records, $github_records)
    {
        $obj = array();

        $cloudflare_domains = array();
        foreach ($cloudflare_records as $cf_record) {
            $domain = $cf_record->name;
            if (!array_key_exists($domain, $cloudflare_domains)) {
                $cloudflare_domains[$domain] = array();
            }
            $cloudflare_domains[$domain][] = array(
                $cf_record->type,
                $cf_record->content,
            );
        }

        foreach ($cloudflare_domains as $domain => $record) {
            if (!property_exists($github_records, $domain)) {
                $obj[] = array(
                    'not-in-github',
                    $domain,
                    $record,
                );
                continue;
            }

            $github_record = $github_records->{$domain}->domains->{$domain};
            if (!self::compareDNS($github_record, $record)) {
                $obj[] = array(
                    'diff-dns-config',
                    $domain,
                    $github_record,
                    $record,
                );
            }
            unset($github_records->{$domain});
        }

        foreach ($github_records as $domain => $github_record) {
            $obj[] = array(
                'not-in-cloudflare',
                $domain,
                $github_record,
            );
        }
        return $obj;
    }

    public static function compareDNS($a, $b)
    {
        $a = array_map('json_encode', $a);
        $b = array_map('json_encode', $b);
        sort($a);
        sort($b);
        return json_encode($a) == json_encode($b);
    }

    public static function getRepoPath($root)
    {
        $config = self::getConfig();
        if (!property_exists($config, $root)) {
            throw new Exception("找不到 {$root} 設定");
        }

        if (!file_exists('/tmp/domain-sync/')) {
            mkdir('/tmp/domain-sync');
        }

        $repo_path = '/tmp/domain-sync/' .str_replace('/', '_', $config->{$root}->github_repo);
        if (!file_exists($repo_path)) {
            chdir(dirname($repo_path));
            system("git clone https://github.com/" . $config->{$root}->github_repo . " " . basename($repo_path), $ret);
        } else {
            chdir($repo_path);
            system("git pull", $ret);
        }

        if ($ret !== 0) {
            throw new Exception("git pull failed");
        }

        return $repo_path;
    }

    public static function handleDiff($diff_records, $root)
    {
        $config = self::getConfig();
        $repo_path = self::getRepoPath($root);

        $obj = new StdClass;
        foreach ($diff_records as $diff_record) {
            $type = array_shift($diff_record);
            $domain = array_shift($diff_record);

            if ($type == 'not-in-github') {
                $path = $repo_path . '/' . trim($config->{$root}->github_path, '/') . '/' . $domain . '.json';
                $domain_setting = array_shift($diff_record);

                file_put_contents($path, json_encode(array(
                    'domains' => array(
                        $domain => $domain_setting,
                    ),
                    'repository' => 'oooooooo',
                    'maintainer' => array(
                        'oooooooo',
                    ),
                ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            } else if ($type == 'not-in-cloudflare') {
                // TODO: 要把 github 設定同步到 cloudflare
            } else if ($type == 'diff-dns-config') {
                // TODO: 要把 github 設定同步到 cloudflare
            }
        }
    }
}
