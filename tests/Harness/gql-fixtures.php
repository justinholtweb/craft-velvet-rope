<?php

// Velvet Rope harness: GraphQL schemas + tokens (idempotent). Token = "velvetrope{name}" padded to 32 chars.
//   velvetrope_plans  the default site + “View membership plans”
//   velvetrope_none   the default site only
// Run with: ddev artisan tinker --execute "require '/var/www/sites/craft-velvetrope/tests/Harness/gql-fixtures.php';"

use CraftCms\Cms\Gql\Data\GqlSchema;
use CraftCms\Cms\Gql\Data\GqlToken;
use CraftCms\Cms\Support\Facades\Gql;
use CraftCms\Cms\Support\Facades\Sites;

$site = 'sites.'.Sites::getPrimarySite()->uid.':read';

foreach (['plans' => [$site, 'velvetrope.plans:read'], 'none' => [$site]] as $name => $scope) {
    $schema = collect(Gql::getSchemas())->first(fn ($s) => $s->name === "velvetrope_{$name}") ?? new GqlSchema;
    $schema->name = "velvetrope_{$name}";
    $schema->scope = $scope;
    Gql::saveSchema($schema) || throw new RuntimeException(json_encode($schema->errors()->all()));

    $token = collect(Gql::getTokens())->first(fn ($t) => $t->name === "velvetrope_{$name}") ?? new GqlToken;
    $token->name = "velvetrope_{$name}";
    $token->schemaId = $schema->id;
    $token->accessToken = str_pad("velvetrope{$name}", 32, '0');
    Gql::saveToken($token) || throw new RuntimeException(json_encode($token->errors()->all()));
}

Gql::invalidateCaches();
echo 'ok';
