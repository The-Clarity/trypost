<?php

declare(strict_types=1);

use App\Actions\AccessToken\BackfillMcpOAuthWorkspaces;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        BackfillMcpOAuthWorkspaces::execute();
    }

    public function down(): void
    {
        // Irreversible data migration — bound tokens keep their workspace_id.
    }
};
