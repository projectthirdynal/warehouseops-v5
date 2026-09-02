<?php

declare(strict_types=1);

namespace Modules\Shop\Console\Commands;

use Illuminate\Console\Command;
use Modules\Shop\Models\FacebookAccount;
use Modules\Shop\Services\FacebookConnectorService;

class ValidateFacebookTokens extends Command
{
    protected $signature = 'meta:validate-tokens';

    protected $description = 'Validate Facebook account tokens and mark expired/revoked connections';

    public function handle(FacebookConnectorService $connector): int
    {
        $accounts = FacebookAccount::query()
            ->whereNull('deleted_at')
            ->where('connection_status', '!=', FacebookAccount::CONNECTION_DISCONNECTED)
            ->get();

        if ($accounts->isEmpty()) {
            $this->info('No Facebook accounts to validate.');

            return self::SUCCESS;
        }

        $valid = 0;
        $invalid = 0;

        foreach ($accounts as $account) {
            $isValid = $connector->validateToken($account);

            if ($isValid) {
                $valid++;
            } else {
                $invalid++;
                $this->warn("Token invalid for account {$account->facebook_user_name} (ID: {$account->facebook_user_id})");
            }
        }

        $this->info("Validated {$accounts->count()} accounts: {$valid} valid, {$invalid} invalid.");

        return self::SUCCESS;
    }
}
