<?php
//(The full, corrected Register.php content will go here)
use MythicalDash\App;
use MythicalDash\Chat\User\User;
use MythicalDash\Middleware\Firewall;
use MythicalDash\Config\ConfigInterface;
use MythicalDash\Chat\columns\UserColumns;
use MythicalDash\Chat\Referral\ReferralUses;
use MythicalDash\Chat\Referral\ReferralCodes;
use MythicalDash\CloudFlare\CloudFlareRealIP;
use MythicalDash\Plugins\Events\Events\AuthEvent;
use MythicalDash\Chat\IPRelationships\IPRelationship;
use MythicalDash\Plugins\Events\Events\ReferralsEvent;
use MythicalDash\Hooks\MythicalSystems\User\UUIDManager;
use MythicalDash\Hooks\MythicalSystems\CloudFlare\Turnstile;
use MythicalDash\Services\Pterodactyl\Admin\Resources\UsersResource;

$router->add('/api/user/auth/register', function (): void {
    global $eventManager;
    global $router;
    App::init();
    $appInstance = App::getInstance(true);
    $config = $appInstance->getConfig();

    $appInstance->allowOnlyPOST();
    
    // All validation checks...
    if (!isset($_POST['firstName']) || $_POST['firstName'] == '') { $appInstance->BadRequest('Bad Request', ['error_code' => 'MISSING_FIRST_NAME']); }
    if (!isset($_POST['lastName']) || $_POST['lastName'] == '') { $appInstance->BadRequest('Bad Request', ['error_code' => 'MISSING_LAST_NAME']); }
    if (!isset($_POST['email']) || $_POST['email'] == '') { $appInstance->BadRequest('Bad Request', ['error_code' => 'MISSING_EMAIL']); }
    if (!isset($_POST['password']) || $_POST['password'] == '') { $appInstance->BadRequest('Bad Request', ['error_code' => 'MISSING_PASSWORD']); }
    if (!isset($_POST['username']) || $_POST['username'] == '') { $appInstance->BadRequest('Bad Request', ['error_code' => 'MISSING_USERNAME']); }
    if (!preg_match('/^[a-zA-Z]+$/', $_POST['firstName'])) { $appInstance->BadRequest('Bad Request', ['error_code' => 'INVALID_FIRST_NAME']);}
    if (!preg_match('/^[a-zA-Z]+$/', $_POST['lastName'])) { $appInstance->BadRequest('Bad Request', ['error_code' => 'INVALID_LAST_NAME']);}
    if (!preg_match('/^[a-zA-Z0-9]+$/', $_POST['username'])) { $appInstance->BadRequest('Bad Request', ['error_code' => 'INVALID_USERNAME']);}
    if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) { $appInstance->BadRequest('Bad Request', ['error_code' => 'INVALID_EMAIL']);}
    if (!preg_match('/^[a-zA-Z0-9_-]{3,32}$/', $_POST['username'])) { $appInstance->BadRequest('Bad Request', ['error_code' => 'INVALID_USERNAME']);}
    if (strlen($_POST['password']) < 8) { $appInstance->BadRequest('Bad Request', ['error_code' => 'PASSWORD_TOO_SHORT']);}
    if (!preg_match('/^[a-z0-9]([\w\.-]+)[a-z0-9]$/i', $_POST['username'])) { $appInstance->BadRequest('Bad Request', ['error_code' => 'INVALID_USERNAME_FORMAT']);}
    if (strlen($_POST['username']) < 1 || strlen($_POST['username']) > 191) { $appInstance->BadRequest('Bad Request', ['error_code' => 'INVALID_USERNAME_LENGTH']);}
    if (strlen($_POST['email']) < 1 || strlen($_POST['email']) > 191) { $appInstance->BadRequest('Bad Request', ['error_code' => 'INVALID_EMAIL_LENGTH']);}
    if (strlen($_POST['firstName']) < 1 || strlen($_POST['firstName']) > 191) { $appInstance->BadRequest('Bad Request', ['error_code' => 'INVALID_FIRST_NAME_LENGTH']);}
    if (strlen($_POST['lastName']) < 1 || strlen($_POST['lastName']) > 191) { $appInstance->BadRequest('Bad Request', ['error_code' => 'INVALID_LAST_NAME_LENGTH']);}

    Firewall::handle($appInstance, CloudFlareRealIP::getRealIP());

    if ($appInstance->getConfig()->getDBSetting(ConfigInterface::TURNSTILE_ENABLED, 'false') == 'true') {
        if (!isset($_POST['turnstileResponse']) || $_POST['turnstileResponse'] == '') { $appInstance->BadRequest('Bad Request', ['error_code' => 'TURNSTILE_FAILED']); }
        $cfTurnstileResponse = $_POST['turnstileResponse'];
        if (!Turnstile::validate($cfTurnstileResponse, CloudFlareRealIP::getRealIP(), $config->getDBSetting(ConfigInterface::TURNSTILE_KEY_PRIV, 'XXXX'))) { $appInstance->BadRequest('Invalid TurnStile Key', ['error_code' => 'TURNSTILE_FAILED']);}
    }

    $firstName = $_POST['firstName'];
    $lastName = $_POST['lastName'];
    $email = $_POST['email'];
    $password = $_POST['password'];
    $username = $_POST['username'];

    try {
        if ($config->getDBSetting(ConfigInterface::PTERODACTYL_BASE_URL, '') == '') { $appInstance->BadRequest('Pterodactyl is not enabled', ['error_code' => 'PTERODACTYL_NOT_ENABLED']); }
        if (User::exists(UserColumns::USERNAME, $username)) { $appInstance->BadRequest('Bad Request', ['error_code' => 'USERNAME_ALREADY_IN_USE']); }
        if (User::exists(UserColumns::EMAIL, $email)) { $appInstance->BadRequest('Bad Request', ['error_code' => 'EMAIL_ALREADY_IN_USE']); }
        
        try {
            $pterodactylUserId = \MythicalDash\Hooks\Pterodactyl\Admin\User::performRegister($firstName, $lastName, $username, $email, $password);
            if ($pterodactylUserId == 0 && $pterodactylUserId != null) { $appInstance->InternalServerError('Internal Server Error', ['error_code' => 'PTERODACTYL_ERROR']); }
            $pteroUsers = new UsersResource($appInstance->getConfig()->getDBSetting(ConfigInterface::PTERODACTYL_BASE_URL, ''), $appInstance->getConfig()->getDBSetting(ConfigInterface::PTERODACTYL_API_KEY, ''));
            \MythicalDash\Hooks\Pterodactyl\Admin\User::performUpdateUser($pteroUsers, $pterodactylUserId, $username, $firstName, $lastName, $email, $password);
        } catch (Exception $e) { $appInstance->InternalServerError('Internal Server Error', ['error_code' => 'PTERODACTYL_ERROR']);}

        User::register($username, $password, $email, $firstName, $lastName, CloudFlareRealIP::getRealIP(), $pterodactylUserId);
        $newUserUuid = User::convertEmailToUUID($email);
        $newUserToken = User::getTokenFromEmail($email);

        if ($config->getDBSetting(ConfigInterface::REFERRALS_ENABLED, false)) {
            if ($newUserUuid) {
                $referralCode = $username . '_' . $appInstance->generatePin();
                ReferralCodes::create($newUserUuid, $referralCode);
                $eventManager->emit(ReferralsEvent::onReferralCreated(), ['user' => $newUserUuid, 'referral_code' => $referralCode]);

                if (isset($_GET['ref']) && $_GET['ref'] != '') {
                    $referrerCode = ReferralCodes::getByCode($_GET['ref']);
                    if (is_array($referrerCode) && isset($referrerCode['user']) && is_string($referrerCode['user']) && $referrerCode['user'] !== '') {
                        $referrerUuid = $referrerCode['user'];
                        try {
                            // CORRECTED DATABASE SYNTAX
                            $dbConnection = \MythicalDash\Chat\Database::getPdoConnection();
                            $stmt = $dbConnection->prepare("INSERT INTO pending_referrals (user_uuid, referrer_uuid) VALUES (?, ?)");
                            $stmt->execute([$newUserUuid, $referrerUuid]);
                        } catch (Exception $e) {
                            $appInstance->getLogger()->error('Failed to save pending referral: ' . $e->getMessage());
                        }
                    }
                }
            }
        }

        $defaultRam = (int) $config->getDBSetting(ConfigInterface::DEFAULT_RAM, 1024);
        $defaultDisk = (int) $config->getDBSetting(ConfigInterface::DEFAULT_DISK, 1024);
        $defaultCpu = (int) $config->getDBSetting(ConfigInterface::DEFAULT_CPU, 100);
        $defaultPorts = (int) $config->getDBSetting(ConfigInterface::DEFAULT_PORTS, 2);
        $defaultDatabases = (int) $config->getDBSetting(ConfigInterface::DEFAULT_DATABASES, 1);
        $defaultServerSlots = (int) $config->getDBSetting(ConfigInterface::DEFAULT_SERVER_SLOTS, 1);
        $defaultBackups = (int) $config->getDBSetting(ConfigInterface::DEFAULT_BACKUPS, 5);
        $defaultBg = $config->getDBSetting(ConfigInterface::DEFAULT_BG, 'https://cdn.mythical.systems/mc.jpg');
        if ($defaultDatabases > 0) { $defaultDatabases = 1; }
        if ($defaultServerSlots > 0) { $defaultServerSlots = 1; }
        if ($defaultBackups > 0) { $defaultBackups = 1; }
        if ($defaultPorts > 0) { $defaultPorts = 1; }

        User::updateInfo($newUserToken, UserColumns::MEMORY_LIMIT, $defaultRam, false);
        User::updateInfo($newUserToken, UserColumns::DISK_LIMIT, $defaultDisk, false);
        User::updateInfo($newUserToken, UserColumns::CPU_LIMIT, $defaultCpu, false);
        User::updateInfo($newUserToken, UserColumns::ALLOCATION_LIMIT, $defaultPorts, false);
        User::updateInfo($newUserToken, UserColumns::DATABASE_LIMIT, $defaultDatabases, false);
        User::updateInfo($newUserToken, UserColumns::SERVER_LIMIT, $defaultServerSlots, false);
        User::updateInfo($newUserToken, UserColumns::BACKUP_LIMIT, $defaultBackups, false);
        User::updateInfo($newUserToken, UserColumns::BACKGROUND, $defaultBg, false);
        $eventManager->emit(AuthEvent::onAuthRegisterSuccess(), ['username' => $username, 'email' => $email]);
        if ($config->getDBSetting(ConfigInterface::IMAGE_HOSTING_ENABLED, 'false') === 'true') {
            $api_key = UUIDManager::generateUUID();
            User::updateInfo($newUserToken, UserColumns::IMAGE_HOSTING_UPLOAD_KEY, $api_key, false);
        }
        IPRelationship::create($newUserUuid, CloudFlareRealIP::getRealIP());
        App::OK('User registered', ['is_first_user' => false]);
    } catch (Exception $e) {
        $appInstance->InternalServerError('Internal Server Error', ['error_code' => 'DATABASE_ERROR']);
    }
});
