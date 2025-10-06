<?php
use MythicalDash\App;
use MythicalDash\Chat\User\User;
use MythicalDash\Middleware\Firewall;
use MythicalDash\Chat\User\Verification;
use MythicalDash\Chat\columns\UserColumns;
use MythicalDash\Chat\User\UserActivities;
use MythicalDash\CloudFlare\CloudFlareRealIP;
use MythicalDash\Chat\interface\UserActivitiesTypes;
use MythicalDash\Chat\columns\EmailVerificationColumns;
use MythicalDash\Config\ConfigInterface;
use MythicalDash\Chat\Referral\ReferralCodes;
use MythicalDash\Chat\Referral\ReferralUses;

$router->get('/api/user/auth/verify', function (): void {
    App::init();
    $appInstance = App::getInstance(true);
    $config = $appInstance->getConfig();

    $appInstance->allowOnlyGET();

    if (isset($_GET['code']) && $_GET['code'] != '') {
        $code = $_GET['code'];
        Firewall::handle($appInstance, CloudFlareRealIP::getRealIP());

        if (Verification::verify($code, EmailVerificationColumns::$type_verify)) {
            $userUuid = Verification::getUserUUID($code);

            if (User::exists(UserColumns::UUID, $userUuid)) {
                $userToken = User::getTokenFromUUID($userUuid);

                if ($userToken != null && $userToken != '') {
                    setcookie('user_token', $userToken, time() + 3600, '/');
                    User::updateInfo($userToken, UserColumns::VERIFIED, 'true', false);

                    if ($config->getDBSetting(ConfigInterface::REFERRALS_ENABLED, false)) {
                        try {
                            // CORRECTED DATABASE SYNTAX
                            $dbConnection = \MythicalDash\Chat\Database::getPdoConnection();
                            $stmt = $dbConnection->prepare("SELECT * FROM pending_referrals WHERE user_uuid = ? LIMIT 1");
                            $stmt->execute([$userUuid]);
                            $pendingReferral = $stmt->fetch();

                            if ($pendingReferral) {
                                $referrerUuid = $pendingReferral['referrer_uuid'];
                                $referrerToken = User::getTokenFromUUID($referrerUuid);
                                $referrerCodeInfo = ReferralCodes::getByUser($referrerUuid);
                                $codeId = $referrerCodeInfo[0]['id'] ?? null;

                                if ($referrerToken && $codeId && $referrerUuid !== $userUuid) {
                                    ReferralUses::create($codeId, $userUuid);
                                    $newUserBonus = intval($config->getDBSetting(ConfigInterface::REFERRALS_COINS_PER_REFERRAL_REDEEMER, 15));
                                    User::addCreditsAtomic($userToken, $newUserBonus);
                                    $referrerBonus = intval($config->getDBSetting(ConfigInterface::REFERRALS_COINS_PER_REFERRAL, 35));
                                    User::addCreditsAtomic($referrerToken, $referrerBonus);
                                    
                                    $deleteStmt = $dbConnection->prepare("DELETE FROM pending_referrals WHERE id = ?");
                                    $deleteStmt->execute([$pendingReferral['id']]);
                                }
                            }
                        } catch (Exception $e) {
                            $appInstance->getLogger()->error('Failed to process pending referral: ' . $e->getMessage());
                        }
                    }

                    Verification::delete($code);
                    UserActivities::add($userUuid, UserActivitiesTypes::$verify, CloudFlareRealIP::getRealIP());
                    header('location: /');
                    exit;
                }
            }
        }
    }
    // Fallback for any failed conditions
    $appInstance->BadRequest('Bad Request', ['error_code' => 'VERIFICATION_FAILED']);
});
