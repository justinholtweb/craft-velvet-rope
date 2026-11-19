<?php

// Velvet Rope harness: a second site, `velvetropefr` (French, <primary>/velvetropefr/), owned by Velvet Rope's
// checks (other agents add and remove their own sites; never touch theirs). Idempotent. The plans
// GraphQL schema is allowed to read it. Remove with multisite-cleanup.php (under bin/harness-lock).

use CraftCms\Cms\Site\Data\Site;
use CraftCms\Cms\Support\Facades\Gql;
use CraftCms\Cms\Support\Facades\Sites;

$fr = Sites::getSiteByHandle('velvetropefr');
$primary = Sites::getPrimarySite();

if ($fr && str_starts_with((string) $fr->baseUrl, '@web')) {
    $fr->baseUrl = rtrim((string) $primary->getBaseUrl(), '/').'/velvetropefr/';
    Sites::saveSite($fr) || throw new RuntimeException('site: '.json_encode($fr->errors()->all()));
}

if (! $fr) {
    $fr = new Site;
    $fr->groupId = $primary->groupId;
    $fr->name = 'Velvet Rope Français';
    $fr->handle = 'velvetropefr';
    $fr->language = 'fr-FR';
    $fr->hasUrls = true;
    // Absolute: Craft 6 beta.1 doesn't resolve `@web` in site base URLs for URL generation.
    $fr->baseUrl = rtrim((string) $primary->getBaseUrl(), '/').'/velvetropefr/';
    $fr->enabled = true;
    Sites::saveSite($fr) || throw new RuntimeException('site: '.json_encode($fr->errors()->all()));
}

$schema = collect(Gql::getSchemas())->first(fn ($s) => $s->name === 'velvetrope_plans');
if ($schema && ! in_array("sites.{$fr->uid}:read", $schema->scope, true)) {
    $schema->scope = [...$schema->scope, "sites.{$fr->uid}:read"];
    Gql::saveSchema($schema);
    Gql::invalidateCaches();
}

echo json_encode(['site' => $fr->id]);
