# MythicalDash Referral Rework

This repository contains a modified, more secure version of the referral system for the [MythicalDash](https://mythicaldash.com/) panel.

## The Problem

The original referral system in MythicalDash awarded referral credits instantly upon user registration. This created a vulnerability where users could easily create multiple alternate ("alt") accounts to farm referral rewards without any real verification.

## The Solution

This rework modifies the process to delay the referral reward until after a new user has successfully **verified their email address**. This ensures that each successful referral is tied to a legitimate, verified account, significantly reducing the potential for abuse.

## How It Works

1.  **Registration:** When a user registers with a referral code, a 'pending' entry is created in a new database table. **No reward is given yet.**
2.  **Verification:** When the new user clicks the verification link in their email, the `Verify.php` script runs. It finds the 'pending' entry, awards the credits to both the referrer and the new user, and then deletes the entry.

---

## Prerequisites

> **Important:** You **must** have **Email Verification** enabled in your MythicalDash admin settings for this modification to work correctly.

---

## Installation Instructions

### 1. Connect to Your Database

You will need to connect to your MySQL/MariaDB server to create a new table.

```bash
# Replace YOUR_USERNAME with your database user (e.g., root)
mysql -u YOUR_USERNAME -p
CREATE TABLE pending_referrals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_uuid VARCHAR(255) NOT NULL,
  referrer_uuid VARCHAR(255) NOT NULL
);
```

### 2. Replace the following files with the ones in this repository
```bash
/var/www/mythicaldash-v3/backend/app/Api/User/Auth/Register.php
/var/www/mythicaldash-v3/backend/app/Api/User/Auth/Register.php
```
