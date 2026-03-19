<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Slide;

/**
 * Durch das Upgrade zu Fabric v7 ändert sich der Standard-Wert für originX/Y von "left"/"top" zu "center".
 * Diese Migration ergänzt originX/Y mit den alten Werten, damit sich die Slides nach dem Upgrade nicht verändern.
 */
class AddOriginPropertiesToSlideBackground extends Migration
{
    private function ensureOriginsInBackground(array &$background): bool
    {
        $changed = false;

        // backgroundImage auf Root-Ebene
        if (array_key_exists('backgroundImage', $background) && is_array($background['backgroundImage'])) {
            if (!array_key_exists('originX', $background['backgroundImage'])) {
                $background['backgroundImage']['originX'] = 'left';
                $changed = true;
            }
            if (!array_key_exists('originY', $background['backgroundImage'])) {
                $background['backgroundImage']['originY'] = 'top';
                $changed = true;
            }
        }

        // Alle Objekte in objects[]
        if (array_key_exists('objects', $background) && is_array($background['objects'])) {
            foreach ($background['objects'] as $idx => $obj) {
                if (!is_array($obj)) {
                    continue;
                }
                if (!array_key_exists('originX', $background['objects'][$idx])) {
                    $background['objects'][$idx]['originX'] = 'left';
                    $changed = true;
                }
                if (!array_key_exists('originY', $background['objects'][$idx])) {
                    $background['objects'][$idx]['originY'] = 'top';
                    $changed = true;
                }
            }
        }

        return $changed;
    }

    private function removeOriginsFromBackground(array &$background): bool
    {
        $changed = false;

        if (array_key_exists('backgroundImage', $background) && is_array($background['backgroundImage'])) {
            if (array_key_exists('originX', $background['backgroundImage'])) {
                unset($background['backgroundImage']['originX']);
                $changed = true;
            }
            if (array_key_exists('originY', $background['backgroundImage'])) {
                unset($background['backgroundImage']['originY']);
                $changed = true;
            }
        }

        if (array_key_exists('objects', $background) && is_array($background['objects'])) {
            foreach ($background['objects'] as $idx => $obj) {
                if (!is_array($obj)) {
                    continue;
                }
                if (array_key_exists('type', $obj) && strtolower((string)$obj['type']) === 'image') {
                    if (array_key_exists('originX', $background['objects'][$idx])) {
                        unset($background['objects'][$idx]['originX']);
                        $changed = true;
                    }
                    if (array_key_exists('originY', $background['objects'][$idx])) {
                        unset($background['objects'][$idx]['originY']);
                        $changed = true;
                    }
                }
            }
        }

        return $changed;
    }

    public function up()
    {
        Slide::chunk(100, function ($slides) {
            foreach ($slides as $slide) {
                $content = json_decode($slide->content, true);
                if (!is_array($content) || !array_key_exists('background', $content)) {
                    continue;
                }

                $backgroundWasString = is_string($content['background']);
                $background = $backgroundWasString ? json_decode($content['background'], true) : $content['background'];

                if (!is_array($background)) {
                    continue;
                }

                $changed = $this->ensureOriginsInBackground($background);

                if ($changed) {
                    $content['background'] = $backgroundWasString ? json_encode($background) : $background;
                    $slide->content = json_encode($content);
                    $slide->save();
                }
            }
        });
    }

    public function down()
    {
        Slide::chunk(100, function ($slides) {
            foreach ($slides as $slide) {
                $content = json_decode($slide->content, true);
                if (!is_array($content) || !array_key_exists('background', $content)) {
                    continue;
                }

                $backgroundWasString = is_string($content['background']);
                $background = $backgroundWasString ? json_decode($content['background'], true) : $content['background'];

                if (!is_array($background)) {
                    continue;
                }

                $changed = $this->removeOriginsFromBackground($background);

                if ($changed) {
                    $content['background'] = $backgroundWasString ? json_encode($background) : $background;
                    $slide->content = json_encode($content);
                    $slide->save();
                }
            }
        });
    }
}
