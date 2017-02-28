<?php

class SyncLib
{
    protected static $_config = null;

    public static function toGithubDomainRecord($record)
    {
        if (property_exists($record, 'priority')) {
            return array($record->type, $record->content, $record->priority);
        } else {
            return array($record->type, $record->content);
        }
    }

    public static function getConfig()
    {
        if (is_null(self::$_config)) {
            self::$_config = json_decode(file_get_contents(__DIR__ . '/config.json'));
        }
        return self::$_config;
    }

    public static function cloudflareRequest($url, $post_params = null, $method = null)
    {
        $headers = array(
            'X-Auth-Email:' . getenv('cloudflare_mail'),
            'X-Auth-Key:' . getenv('cloudflare_key'),
        );
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        if (!is_null($method)) {
            curl_Setopt($curl, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        }

        if (!is_null($post_params)) {
            if (is_array($post_params)) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, implode('&', array_map(function($k) use ($post_params) {
                    return urlencode($k) . '=' . urlencode($post_params[$k]);
                }, array_keys($post_params))));
            } elseif (is_string($post_params)) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, $post_params);
            }
            $headers[] = 'Content-type: application/json';
        }
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        $content = curl_exec($curl);
        if (!$obj = json_decode($content)) {
            throw new Exception("Response from {$url} is not valid JSON: " . $content);
        }
        if (!property_exists($obj, 'success') or !$obj->success) {
            throw new Exception("Response from {$url} is not success: " . $content);
        }

        curl_close($curl);
        return $obj;
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
        $obj = self::cloudflareRequest($url);
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
            $cloudflare_domains[$domain][] = $cf_record;
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
            $cloudflare_record = array_map(array('self', 'toGithubDomainRecord'), $record);
            if (!self::compareDNS($github_record, $cloudflare_record)) {
                $obj[] = array(
                    'diff-dns-config',
                    $domain,
                    $github_record,
                    $record,
                    $github_records->{$domain}->filename
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
        $path = $repo_path . '/' . trim($config->{$root}->github_path, '/');
        if (!file_exists($path)) {
            mkdir($path);
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
                $command = trim(readline("{$domain} 設定在 cloudflare 存在，請問你要? \nnew) 增加到 github\ndelete) 從 cloudflare 刪除\n[new|delete]: "));

                if ('new' == $command) {
                    error_log("github 中沒有 {$domain} ，自動產生 /{$domain}.json");

                    $path = $repo_path . '/' . trim($config->{$root}->github_path, '/') . '/' . $domain . '.json';
                    $domain_setting = array_map(array('self', 'toGithubDomainRecord'), array_shift($diff_record));

                    file_put_contents($path, json_encode(array(
                        'domains' => array(
                            $domain => $domain_setting,
                        ),
                        'repository' => 'oooooooo',
                        'maintainer' => array(
                            'oooooooo',
                        ),
                    ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                } elseif ('delete' == $command) {
                    $url = sprintf("https://api.cloudflare.com/client/v4/zones/%s/dns_records/",  $config->{$root}->cloudflare_zoneid);
                    $domain_config = self::getCloudFlareConfig($root);
                    putenv('cloudflare_key=' . $domain_config->cloudflare_key);
                    putenv('cloudflare_mail=' . $domain_config->cloudflare_mail);
                    
                    foreach ($diff_record[0] as $dns_record) {
                        $request = self::cloudflareRequest($url . $dns_record->id, '', 'DELETE');
                        error_log(json_encode($request));
                    }
                } else {
                    throw new Exception("不明的指令 {$command}");
                }
            } else if ($type == 'not-in-cloudflare') {
                if ('y' != trim(readline("是否要把 {$domain} 設定上傳到 cloudflare (y/n) "))) {
                    continue;
                }

                $url = sprintf("https://api.cloudflare.com/client/v4/zones/%s/dns_records/",  $config->{$root}->cloudflare_zoneid);
                $domain_config = self::getCloudFlareConfig($root);
                putenv('cloudflare_key=' . $domain_config->cloudflare_key);
                putenv('cloudflare_mail=' . $domain_config->cloudflare_mail);
                    
                foreach ($diff_record[0]->domains->{$domain} as $domain_config) {
                    $v = array(
                        'type' => $domain_config[0],
                        'name' => $domain,
                        'content' => $domain_config[1],
                    );
                    if ($v['type'] == 'MX' and array_key_exists(2, $domain_config)) {
                        $v['priority'] = $domain_config[2];
                    };
                    $request = self::cloudflareRequest($url, json_encode($v));
                }
            } else if ($type == 'diff-dns-config') {
                list($github_record, $cloudflare_record, $github_path) = $diff_record;
                echo "{$domain} 的設定在 github 和 cloudflare 不相同，請問您要以哪邊為準\n";
                echo "github) " . json_encode($github_record) . "\n";
                echo "cloudflare) " . json_encode(array_map(array('self', 'toGithubDomainRecord'), $cloudflare_record)) . "\n";
                $command = trim(readline("[github|cloudflare]: "));

                if ('github' == $command) { // 以 github 為準
                    $url = sprintf("https://api.cloudflare.com/client/v4/zones/%s/dns_records/",  $config->{$root}->cloudflare_zoneid);
                    $domain_config = self::getCloudFlareConfig($root);
                    putenv('cloudflare_key=' . $domain_config->cloudflare_key);
                    putenv('cloudflare_mail=' . $domain_config->cloudflare_mail);
                    
                    // 先刪除掉 cloudflare 資料
                    foreach ($cloudflare_record as $dns_record) {
                        $request = self::cloudflareRequest($url . $dns_record->id, '', 'DELETE');
                        error_log(json_encode($request));
                    }

                    // 再把 github 資料推上去
                    foreach ($github_record as $domain_config) {
                        $v = array(
                            'type' => $domain_config[0],
                            'name' => $domain,
                            'content' => $domain_config[1],
                        );
                        if ($domain_config[0] == 'MX' and array_key_exists(2, $domain_config)) {
                            $v['priority'] = $domain_config[2];
                        }
                        $request = self::cloudflareRequest($url, json_encode($v));
                    }
                } elseif ('cloudflare' == $command) {
                    error_log("更新 {$github_path}");

                    $obj = json_decode(file_get_contents($github_path));
                    $obj->domains->{$domain} = array_map(array('self', 'toGithubDomainRecord'), $cloudflare_record);

                    file_put_contents($github_path, json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                } else {
                    throw new Exception("不明的指令 {$command}");
                }
            }
        }
    }
}
