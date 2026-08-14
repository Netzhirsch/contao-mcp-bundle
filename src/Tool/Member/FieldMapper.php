<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Member;

use Contao\Config;
use Contao\FilesModel;
use Contao\MemberGroupModel;
use Contao\MemberModel;
use Doctrine\DBAL\Connection;

/**
 * Maps the MCP `fields`-object to MemberModel column writes.
 *
 * Specialities:
 *   - password is plain-text on input and hashed via password_hash(PASSWORD_DEFAULT)
 *     before storage. Enforces the configured min-length.
 *   - active is the positive flip of `disable` (Contao's `reverseToggle`).
 *   - groups is a list<int> of tl_member_group.id — written as a serialized blob.
 *   - home_dir is a file path that we resolve to a binary UUID.
 *   - Read-only fields (date_added, last_login, current_login, secret, session,
 *     etc.) are rejected with a clear error if the caller tries to set them.
 */
final class FieldMapper
{
    /**
     * @var list<string>
     */
    private const READ_ONLY_FIELDS = [
        'date_added', 'dateAdded',
        'last_login', 'lastLogin',
        'current_login', 'currentLogin',
        'session', 'secret', 'backupCodes', 'trustedTokenVersion',
        'loginAttempts', 'locked', 'useTwoFactor',
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{errors: list<string>, applied: int}
     */
    public function apply(MemberModel $m, array $input, bool $isCreate): array
    {
        $errors = [];
        $applied = 0;

        foreach (array_keys($input) as $key) {
            if (\in_array($key, self::READ_ONLY_FIELDS, true)) {
                $errors[] = sprintf('"%s" is read-only and cannot be set via MCP', $key);
            }
        }
        if ($errors !== []) {
            return ['errors' => $errors, 'applied' => 0];
        }

        if (\array_key_exists('username', $input)) {
            $value = trim((string) $input['username']);
            if ($value === '') {
                $errors[] = 'username must not be empty';
            } elseif (preg_match('/\s/', $value) === 1) {
                $errors[] = 'username must not contain whitespace';
            } else {
                if ($this->isDuplicateUsername($value, (int) $m->id)) {
                    $errors[] = sprintf('username "%s" is already in use', $value);
                } else {
                    $m->username = mb_substr($value, 0, 64);
                    ++$applied;
                }
            }
        } elseif ($isCreate) {
            $errors[] = 'username is required';
        }

        if (\array_key_exists('email', $input)) {
            $value = trim((string) $input['email']);
            if ($value === '' || !filter_var($value, \FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'email must be a valid address';
            } else {
                if ($this->isDuplicateEmail($value, (int) $m->id)) {
                    $errors[] = sprintf('email "%s" is already in use', $value);
                } else {
                    $m->email = mb_substr($value, 0, 255);
                    ++$applied;
                }
            }
        } elseif ($isCreate) {
            $errors[] = 'email is required';
        }

        foreach (['firstname', 'lastname'] as $required) {
            if (\array_key_exists($required, $input)) {
                $value = trim((string) $input[$required]);
                if ($value === '') {
                    $errors[] = "{$required} must not be empty";
                } else {
                    $m->{$required} = mb_substr($value, 0, 255);
                    ++$applied;
                }
            } elseif ($isCreate) {
                $errors[] = "{$required} is required";
            }
        }

        if (\array_key_exists('password', $input)) {
            $value = (string) $input['password'];
            $min = (int) (Config::get('minPasswordLength') ?: 8);
            if ($value === '') {
                $errors[] = 'password must not be empty (omit the key to keep existing)';
            } elseif (mb_strlen($value) < $min) {
                $errors[] = sprintf('password must be at least %d characters', $min);
            } else {
                $m->password = (string) password_hash($value, \PASSWORD_DEFAULT);
                ++$applied;
            }
        } elseif ($isCreate) {
            $errors[] = 'password is required on create';
        }

        // Optional text fields (mass-mapping).
        $textFields = [
            'gender' => ['column' => 'gender', 'enum' => ['male', 'female', 'other', '']],
            'date_of_birth' => ['column' => 'dateOfBirth'],
            'language' => ['column' => 'language'],
            'company' => ['column' => 'company'],
            'street' => ['column' => 'street'],
            'postal' => ['column' => 'postal'],
            'city' => ['column' => 'city'],
            'state' => ['column' => 'state'],
            'country' => ['column' => 'country'],
            'phone' => ['column' => 'phone'],
            'mobile' => ['column' => 'mobile'],
            'fax' => ['column' => 'fax'],
            'website' => ['column' => 'website'],
            'start' => ['column' => 'start'],
            'stop' => ['column' => 'stop'],
        ];

        foreach ($textFields as $key => $cfg) {
            if (!\array_key_exists($key, $input)) {
                continue;
            }
            $value = (string) ($input[$key] ?? '');
            if (isset($cfg['enum']) && !\in_array($value, $cfg['enum'], true)) {
                $errors[] = sprintf('%s must be one of: %s', $key, implode(', ', $cfg['enum']));
                continue;
            }
            $m->{$cfg['column']} = $value;
            ++$applied;
        }

        // Bool flags. `active` is the positive form of Contao's reverseToggle `disable`.
        if (\array_key_exists('login', $input)) {
            $m->login = (bool) $input['login'] ? 1 : 0;
            ++$applied;
        }
        if (\array_key_exists('active', $input)) {
            $m->disable = (bool) $input['active'] ? 0 : 1;
            ++$applied;
        } elseif (\array_key_exists('disable', $input)) {
            // Honour the raw Contao field name as escape hatch.
            $m->disable = (bool) $input['disable'] ? 1 : 0;
            ++$applied;
        }
        if (\array_key_exists('assign_dir', $input)) {
            $m->assignDir = (bool) $input['assign_dir'] ? 1 : 0;
            ++$applied;
        }

        if (\array_key_exists('groups', $input)) {
            $value = $input['groups'];
            if (!\is_array($value) || !array_is_list($value)) {
                $errors[] = 'groups must be a list of tl_member_group ids';
            } else {
                $ids = [];
                $bad = false;
                foreach ($value as $id) {
                    $id = (int) $id;
                    if ($id <= 0 || MemberGroupModel::findByPk($id) === null) {
                        $errors[] = sprintf('Unknown member_group id: %d', $id);
                        $bad = true;
                        break;
                    }
                    $ids[] = $id;
                }
                if (!$bad) {
                    $m->groups = $ids === [] ? '' : serialize($ids);
                    ++$applied;
                }
            }
        }

        if (\array_key_exists('home_dir', $input)) {
            $value = $input['home_dir'];
            if ($value === null || $value === '') {
                $m->homeDir = null;
                ++$applied;
            } elseif (\is_string($value)) {
                $file = FilesModel::findByPath($value);
                if ($file === null) {
                    $errors[] = sprintf('home_dir "%s" not found in tl_files', $value);
                } else {
                    $m->homeDir = (string) $file->uuid;
                    ++$applied;
                }
            } else {
                $errors[] = 'home_dir must be a file path string';
            }
        }

        return ['errors' => $errors, 'applied' => $applied];
    }

    private function isDuplicateUsername(string $username, int $excludeId): bool
    {
        if ($excludeId > 0) {
            $count = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM tl_member WHERE username = ? AND id != ?',
                [$username, $excludeId],
            );
        } else {
            $count = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM tl_member WHERE username = ?',
                [$username],
            );
        }

        return $count > 0;
    }

    private function isDuplicateEmail(string $email, int $excludeId): bool
    {
        if ($excludeId > 0) {
            $count = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM tl_member WHERE email = ? AND id != ?',
                [$email, $excludeId],
            );
        } else {
            $count = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM tl_member WHERE email = ?',
                [$email],
            );
        }

        return $count > 0;
    }
}
