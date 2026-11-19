<?php

// Removes the `velvetropefr` site (and only that one). Run under bin/harness-lock.
use CraftCms\Cms\Support\Facades\Sites;

if ($fr = Sites::getSiteByHandle('velvetropefr')) {
    Sites::deleteSite($fr);
}
echo Sites::getSiteByHandle('velvetropefr') ? 'still there' : 'removed';
