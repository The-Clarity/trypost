<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_auth_codes', function (Blueprint $table) {
            $table->foreignUuid('workspace_id')
                ->nullable()
                ->after('client_id')
                ->index();

            $table->foreign('workspace_id')
                ->references('id')
                ->on('workspaces')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('oauth_auth_codes', function (Blueprint $table) {
            $table->dropForeign(['workspace_id']);
            $table->dropColumn('workspace_id');
        });
    }

    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
