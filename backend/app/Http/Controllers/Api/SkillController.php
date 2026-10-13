<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\File;

class SkillController extends Controller
{
    public function index()
    {
        $base = rtrim((string) env('HERMES_SKILLS_DIR', '/home/ROG/.hermes/skills'), '/');

        if (! is_dir($base)) {
            return response()->json(['skills' => []]);
        }

        $skills = [];
        foreach (File::allFiles($base) as $file) {
            if ($file->getFilename() !== 'SKILL.md') {
                continue;
            }

            $rel = str_replace($base.'/', '', $file->getPathname());
            $parts = explode('/', $rel);
            $category = count($parts) > 2 ? $parts[0] : '';

            [$name, $description] = $this->parseFrontmatter($file->getPathname());

            $skills[] = [
                'name' => $name ?: basename(dirname($file->getPathname())),
                'description' => $description,
                'category' => $category,
            ];
        }

        usort($skills, fn ($a, $b) => [$a['category'], $a['name']] <=> [$b['category'], $b['name']]);

        return response()->json(['skills' => $skills]);
    }

    // ponytail: naive front-matter read (name/description lines, quotes
    // stripped). Multi-line YAML block scalars fall back to empty — switch to a
    // real YAML parser if such descriptions start showing up blank.
    private function parseFrontmatter(string $path): array
    {
        $head = '';
        $fh = @fopen($path, 'r');
        if ($fh) {
            for ($i = 0; $i < 40 && ($line = fgets($fh)) !== false; $i++) {
                $head .= $line;
                if ($i > 0 && trim($line) === '---') {
                    break;
                }
            }
            fclose($fh);
        }

        $name = preg_match('/^name:\s*(.+)$/m', $head, $m) ? trim($m[1], " \t\"'") : '';
        $description = preg_match('/^description:\s*(.+)$/m', $head, $m) ? trim($m[1], " \t\"'") : '';

        return [$name, $description];
    }
}
