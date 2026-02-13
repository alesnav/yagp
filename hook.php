<?php

/**
 * -------------------------------------------------------------------------
 * YAGP plugin for GLPI
 * Copyright (C) 2019-2025 by the TICgal Team.
 * https://tic.gal/en/project/yagp-yet-another-glpi-plugin/
 * -------------------------------------------------------------------------
 * LICENSE
 * This file is part of the YAGP plugin.
 * YAGP plugin is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 * YAGP plugin is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with YAGP. If not, see <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @package   yagp
 * @author    the TICGAL team
 * @copyright Copyright (c) 2025 TICGAL team
 * @license   AGPL License 3.0 or (at your option) any later version
 *            http://www.gnu.org/licenses/agpl-3.0-standalone.html
 * @link      https://www.tic.gal
 * @since     2019
 * -------------------------------------------------------------------------
 */

/**
 * Install all necessary elements for the plugin
 *
 * @return boolean True if success
 */
function plugin_yagp_install(): bool
{
    $migration = new Migration(PLUGIN_YAGP_VERSION);

    // Parse inc directory
    foreach (glob(dirname(__FILE__) . '/inc/*') as $filepath) {
        // Load *.class.php files and get the class name
        if (preg_match("/inc.(.+)\.class.php/", $filepath, $matches)) {
            $classname = 'PluginYagp' . ucfirst($matches[1]);
            include_once($filepath);
            // If the install method exists, load it
            if (method_exists($classname, 'install')) {
                $classname::install($migration);
            }
        }
    }

    return true;
}

/**
 * Uninstall previously installed elements of the plugin
 *
 * @return boolean True if success
 */
function plugin_yagp_uninstall(): bool
{
    $migration = new Migration(PLUGIN_YAGP_VERSION);

    // Parse inc directory
    foreach (glob(dirname(__FILE__) . '/inc/*') as $filepath) {
        // Load *.class.php files and get the class name
        if (preg_match("/inc.(.+)\.class.php/", $filepath, $matches)) {
            $classname = 'PluginYagp' . ucfirst($matches[1]);
            include_once($filepath);
            // If the install method exists, load it
            if (method_exists($classname, 'uninstall')) {
                $classname::uninstall($migration);
            }
        }
    }

    return true;
}

/**
 * plugin_yagp_updateitem
 *
 * @param  CommonDBTM $item
 * @return void
 */
function plugin_yagp_updateitem(CommonDBTM $item): void
{
    if ($item::getType() == "PluginYagpConfig") {
        /** @var PluginYagpConfig $item */
        $input = $item->input;
        if ($input["ticketsolveddate"] == 1) {
            CronTask::register("PluginYagpTicketsolveddate", 'changeDate', HOUR_TIMESTAMP, [
                'state' => 1,
                'mode'  => CronTask::MODE_EXTERNAL,
            ]);
        } elseif ($input["ticketsolveddate"] == 0) {
            CronTask::unregister("YagpTicketsolveddate");
        }
    }
}

/**
 * plugin_yagp_getAddSearchOptions
 *
 * @param  mixed $itemtype
 * @return array
 */
function plugin_yagp_getAddSearchOptions($itemtype): array
{
    $config = PluginYagpConfig::getInstance();

    $sopt = [];
    if ($config->fields['recategorization']) {
        switch ($itemtype) {
            case "Ticket":
                $sopt['yagp'] = ['name' => 'YAGP'];

                $sopt[9021321] = [
                    'table'                 => PluginYagpTicket::getTable(),
                    'field'                 => 'is_recategorized',
                    'name'                  => __('Recategorized', 'yagp'),
                    'searchtype'            => ['equals', 'notequals'],
                    'massiveaction'         => false,
                    'searchequalsonfield'   => true,
                    'datatype'              => 'specific',
                    'joinparams' => [
                        'jointype'          => 'child',
                        'linkfield'         => 'tickets_id',
                    ],
                ];

                $sopt[9021322] = [
                    'table'                 => PluginYagpTicket::getTable(),
                    'field'                 => 'plugin_yagp_itilcategories_id',
                    'name'                  => __('Initial category', 'yagp'),
                    'searchtype'            => ['equals', 'notequals'],
                    'massiveaction'         => false,
                    'searchequalsonfield'   => true,
                    'datatype'              => 'specific',
                    'joinparams' => [
                        'jointype'          => 'child',
                        'linkfield'         => 'tickets_id',
                    ],
                ];
        }
    }
    return $sopt;
}

/**
 * Plugin_Yagp_addDefaultJoin
 *
 * @param  mixed $in
 * @return array
 */
function Plugin_Yagp_addDefaultJoin($in): array
{
    return is_array($in) ? $in : [];
}

/**
 * Plugin_Yagp_addDefaultWhere
 *
 * @param  array $in
 * @return array
 */
function Plugin_Yagp_addDefaultWhere(array $in): array
{
    if (!is_array($in) || count($in) < 2) {
        return is_array($in) ? $in : [];
    }

    $itemtype = $in[0];
    $where    = $in[1];

    if (!PluginYagpProfile::getAllocatorPermission()) {
        return [$itemtype, $where];
    }

    if ($itemtype === Ticket::class) {
        $allocatorSQL = PluginYagpProfile::getAllocatorSQLTickets();
        $subquery = "SELECT `tickets_id` FROM ($allocatorSQL) as `yagp_filter`";

        $condition = "`glpi_tickets`.`id` IN ($subquery)";

        if (is_array($where)) {
            $where[] = $condition;
        }
        else if (is_string($where)) {
            $where .= " AND $condition";
        }
    }

    return [$itemtype, $where];
}

/**
 * @param array $params
 *
 * @return void
 */
function plugin_yagp_pre_show_tab(array $params): void
{
    $config = PluginYagpConfig::getInstance();

    if ($config->fields['change_df_min_val']) {
        PluginYagpPreshowtab::preShowTab($params);
    }

    if ($config->fields['hide_historical']) {
        PluginYagpPreshowtab::plugin_yagp_preShowTab($params);
    }
}

/**
 * @param array $params
 *
 * @return void
 */
function plugin_yagp_post_show_tab(array $params): void
{
    /** @var \DBmysql $DB */
    global $DB;

    $config = PluginYagpConfig::getInstance();

    if (isset($params['item']) && $params['item'] instanceof CommonDBTM) {
        $item = $params['item'];
        if (
            $item->getType() == 'Ticket'
            && isset($params['options']['tabnum'])
            && $params['options']['tabnum'] == 3
        ) {
            /** @var Ticket $item */
            $query = [
                'FROM' => TicketSatisfaction::getTable(),
                'WHERE' => [
                    'tickets_id' => $item->getID(),
                    'date_answered' => null,
                ],
            ];
            $req = $DB->request($query);
            if (count($req) == 1) {
                $minstart = $config->fields['default_satisfaction'];
                $script = <<<JAVASCRIPT
                    $(document).ready(function() {
                        $('#stars').rateit('value', {$minstart});
                    });
    JAVASCRIPT;
                echo Html::scriptBlock($script);
            }
        }
    }
}
