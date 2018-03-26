<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Class containing helper methods for processing data requests.
 *
 * @package    tool_dataprivacy
 * @copyright  2018 Adrian Greeve
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace tool_dataprivacy;

defined('MOODLE_INTERNAL') || die();

/**
 * Class containing helper methods for processing data requests.
 *
 * @copyright  2018 Adrian Greeve
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class metadata_registry {

    public function do_what_i_want_take_2() {
        $manager = new \core_privacy\manager();
        $pluginman = \core_plugin_manager::instance();
        $contributedplugins = $this->get_contrib_list();
        $metadata = $manager->get_metadata_for_components();
        $fullyrichtree = $this->get_full_component_list();
        foreach ($fullyrichtree as $key => $values) {
            $plugintype = $values['plugin_type']; 
            $plugins = array_map(function($component) use ($manager, $metadata, $contributedplugins, $plugintype, $pluginman) {
                // Use the plugin name for the plugins, ignore for core subsystems.
                $internaldata = ($plugintype == 'core') ? ['component' => $component] :
                        ['component' => $pluginman->plugin_name($component)];
                $internaldata['raw_component'] = $component;
                if ($manager->component_is_compliant($component)) {
                    $internaldata['compliant'] = true;
                    if (isset($metadata[$component])) {
                        $collection = $metadata[$component]->get_collection();
                        $internaldata = $this->format_metadata($collection, $component, $internaldata);
                    } else {
                        // Call get_reason for null provider.
                        $internaldata['nullprovider'] = get_string($manager->get_null_provider_reason($component), $component);
                    }
                } else {
                    $internaldata['compliant'] = false;
                }
                // Check to see if we are an external plugin.
                $componentshortname = explode('_', $component);
                $shortname = array_pop($componentshortname);
                if (isset($contributedplugins[$plugintype][$shortname])) {
                    $internaldata['external'] = true;
                }
                return $internaldata;
            }, $values['plugins']);
            $fullyrichtree[$key]['plugin_type_raw'] = $plugintype;
            // We're done using the plugin type. Convert it to a readable string.
            $fullyrichtree[$key]['plugin_type'] = $pluginman->plugintype_name($plugintype);
            $fullyrichtree[$key]['plugins'] = $plugins;
        }
        return $fullyrichtree;
    }

    protected function format_metadata($collection, $component, $internaldata) {
        foreach ($collection as $collectioninfo) {
            $privacyfields = $collectioninfo->get_privacy_fields();
            $fields = '';
            if (!empty($privacyfields)) {
                $fields = array_map(function($key, $field) use ($component) {
                    return [
                        'field_name' => $key,
                        'field_summary' => get_string($field, $component)
                    ];
                }, array_keys($privacyfields), $privacyfields);
            }
            // Can the metadata types be located somewhere else besides core?
            $items = explode('\\', get_class($collectioninfo));
            $type = array_pop($items);
            $drag = [
                'name' => $collectioninfo->get_name(),
                'type' => $type,
                'fields' => $fields,
                'summary' => get_string($collectioninfo->get_summary(), $component)
            ];
            if (strpos($type, 'subsystem_link') === 0 || strpos($type, 'plugintype_link') === 0) {
                $drag['link'] = true;
            }
            $internaldata['metadata'][] = $drag;
        }
        return $internaldata;
    }

    protected function get_full_component_list() {
        $root = array_map(function($plugintype) {
            return [
                'plugin_type' => $plugintype,
                'plugins' => array_map(function($pluginname) use ($plugintype) {
                    return $plugintype . '_' . $pluginname;
                }, array_keys(\core_component::get_plugin_list($plugintype)))
            ];
        }, array_keys(\core_component::get_plugin_types()));
        // Add subsystems.
        $corenames = array_map(function($name) {
            return 'core_' . $name;
        }, array_keys(array_filter(\core_component::get_core_subsystems(), function($path) {
                return isset($path);
        })));
        $root[] = ['plugin_type' => 'core', 'plugins' => $corenames];
        return $root;
    }

    /**
     * Returns a list of contributed plugins installed on the system.
     *
     * @return array A list of contributed plugins installed.
     */
    protected function get_contrib_list() {
        return array_map(function($plugins) {
            return array_filter($plugins, function($plugindata) {
                return !$plugindata->is_standard();
            });    
        }, \core_plugin_manager::instance()->get_plugins());
    }
}
