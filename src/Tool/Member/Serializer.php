<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Member;

use Contao\FilesModel;
use Contao\MemberModel;
use Contao\StringUtil;

/**
 * Renders MemberModel rows. We NEVER expose `password`, `secret` or `session`
 * in the output — those are credential / state fields whose plaintext leakage
 * via MCP would be a security incident.
 */
final class Serializer
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(MemberModel $m): array
    {
        return [
            'id' => (int) $m->id,
            'username' => (string) $m->username,
            'email' => (string) $m->email,
            'firstname' => (string) $m->firstname,
            'lastname' => (string) $m->lastname,
            'login' => (bool) $m->login,
            'active' => !(bool) $m->disable,
            'date_added' => (int) $m->dateAdded,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function full(MemberModel $m): array
    {
        return self::summary($m) + [
            'gender' => (string) $m->gender,
            'date_of_birth' => (string) $m->dateOfBirth,
            'language' => (string) $m->language,
            'company' => (string) $m->company,
            'street' => (string) $m->street,
            'postal' => (string) $m->postal,
            'city' => (string) $m->city,
            'state' => (string) $m->state,
            'country' => (string) $m->country,
            'phone' => (string) $m->phone,
            'mobile' => (string) $m->mobile,
            'fax' => (string) $m->fax,
            'website' => (string) $m->website,
            'groups' => self::groupIds($m->groups),
            'assign_dir' => (bool) $m->assignDir,
            'home_dir' => self::resolveFilePath($m->homeDir),
            'start' => (string) $m->start,
            'stop' => (string) $m->stop,
            'last_login' => (int) $m->lastLogin,
            'current_login' => (int) $m->currentLogin,
            'tstamp' => (int) $m->tstamp,
            // Deliberately omitted: password, secret, session, backupCodes,
            // useTwoFactor, trustedTokenVersion, loginAttempts, locked.
            //
            // password and secret are credential material; the rest is
            // 2FA / lockout state that would let an attacker probe accounts.
        ];
    }

    /**
     * @return list<int>
     */
    private static function groupIds(mixed $blob): array
    {
        $list = StringUtil::deserialize($blob, true);
        return array_values(array_map('intval', array_filter($list, static fn ($v): bool => $v !== '' && $v !== null)));
    }

    private static function resolveFilePath(mixed $uuid): ?string
    {
        if (!\is_string($uuid) || $uuid === '') {
            return null;
        }
        $model = FilesModel::findByUuid($uuid);

        return $model !== null ? (string) $model->path : null;
    }
}
