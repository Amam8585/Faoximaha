<?php

declare(strict_types=1);

namespace Faoxima\Provisioning;

final class ConfigEditor
{
    /** @param array{name:string,user:string,password:string} $database */
    public static function render(string $template, array $database, string $token, string $adminId, string $domainPath, string $username): string
    {
        $contents = file_get_contents($template);
        if ($contents === false) {
            throw new ProvisioningException("Cannot read config template {$template}");
        }
        $values = [
            'dbname' => $database['name'], 'usernamedb' => $database['user'], 'passworddb' => $database['password'],
            'dbhost' => 'localhost', 'APIKEY' => $token, 'adminnumber' => $adminId,
            'domainhosts' => $domainPath, 'usernamebot' => $username,
        ];
        foreach ($values as $variable => $value) {
            $pattern = '/^(\$' . preg_quote($variable, '/') . '\s*=\s*)([\'\"])(.*?)(\2)(\s*;.*)$/m';
            $count = 0;
            $replacement = static fn (array $match): string => $match[1] . var_export($value, true) . $match[5];
            $contents = preg_replace_callback($pattern, $replacement, $contents, 1, $count) ?? '';
            if ($count !== 1) {
                throw new ProvisioningException("Config template has no unique \${$variable} assignment.");
            }
        }
        return $contents;
    }

    /** @return array{name:string,user:string,password:string,token:string,admin:string,domain:string,username:string} */
    public static function read(string $config): array
    {
        $contents = file_get_contents($config);
        if ($contents === false) { throw new ProvisioningException("Cannot read {$config}"); }
        $map = ['dbname' => 'name', 'usernamedb' => 'user', 'passworddb' => 'password', 'APIKEY' => 'token',
            'adminnumber' => 'admin', 'domainhosts' => 'domain', 'usernamebot' => 'username'];
        $result = [];
        foreach ($map as $variable => $key) {
            if (!preg_match('/^\$' . preg_quote($variable, '/') . '\s*=\s*([\'\"])(.*?)\1\s*;/m', $contents, $match)) {
                throw new ProvisioningException("Configured \${$variable} is missing.");
            }
            $result[$key] = stripcslashes($match[2]);
        }
        /** @var array{name:string,user:string,password:string,token:string,admin:string,domain:string,username:string} $result */
        return $result;
    }
}
