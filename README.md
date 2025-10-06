# Mythicaldash-Referall-reworked
Reworked version of Referral codes for MythicalDash

Original code users could register accounts after accounts and they will count towards the referral 
The rework now makes sure that users can only receive a successful referral after the successful registration, and email verification reducing number of abuse.

**To use this you must have Email Verification turned on in settings!**

1. Create a new database table with the following:

First enter mysql using:
mysql -u root -p

Then copy and paste this:

CREATE TABLE pending_referrals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_uuid VARCHAR(255) NOT NULL,
  referrer_uuid VARCHAR(255) NOT NULL
);


2. After you created the new database replace the following files:
/var/www/mythicaldash-v3/backend/app/Api/User/Auth/Register.php
/var/www/mythicaldash-v3/backend/app/Api/User/Auth/Verify.php

With the new files listed in the repo. 

