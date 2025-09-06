<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MParameterController extends Controller
{
    
    //
    // functions to edit m_parameters
    //

    public function listMparameter(Request $req)
    {
        $q = DB::table('m_parameter')
            ->select([
                'id','name','context','level','type','value','min','max','step',
                'first_program','sequence','ui_label','ui_description'
            ])
            ->orderBy('sequence');

        if ($req->filled('context'))        $q->where('context', $req->string('context'));
        if ($req->filled('level'))          $q->where('level',   $req->integer('level'));
        if ($req->filled('first_program'))  $q->where('first_program', $req->integer('first_program'));

        return response()->json(['items' => $q->get()]);
    }

    public function updateMparameter(Request $req, int $id)
    {
        $data = $req->validate([
            'name'           => 'nullable|string|max:255',
            'ui_label'       => 'nullable|string|max:255',
            'ui_description' => 'nullable|string',
            'context'        => 'nullable|in:input,expert,protected,finale',
            'level'          => 'required|integer',
            'type'           => 'nullable|in:integer,decimal,time,date,boolean',
            'first_program'  => 'nullable|integer',
            'value'          => 'nullable|string|max:255',
            'min'            => 'nullable|string|max:255',
            'max'            => 'nullable|string|max:255',
            'step'           => 'nullable|string|max:255',
        ]);

        DB::table('m_parameter')->where('id', $id)->update($data);
        $row = DB::table('m_parameter')->where('id', $id)->first();

        return response()->json($row);
    }

    public function reorderMparameter(Request $req)
    {
        // Erwartet: { order: [ { id, sequence }, ... ] }
        $data = $req->validate([
            'order' => 'required|array|min:1',
            'order.*.id' => 'required|integer',
            'order.*.sequence' => 'required|integer|min:1',
        ]);

        // Payload normalisieren → Map: id => sequence
        $payloadMap = collect($data['order'])
            ->map(fn ($r) => ['id' => (int)$r['id'], 'sequence' => (int)$r['sequence']])
            ->keyBy('id');

        $ids = $payloadMap->keys()->all();

        // Aktuelle Sequenzen der betroffenen IDs holen (einmalig)
        $current = DB::table('m_parameter')
            ->whereIn('id', $ids)
            ->pluck('sequence', 'id'); // Map: id => sequence

        // Nur geänderte Kandidaten herausfiltern
        $changed = [];
        foreach ($payloadMap as $id => $seq) {
            if (!isset($current[$id])) {
                // unbekannte ID ignorieren
                continue;
            }
            if ((int)$current[$id] !== (int)$seq['sequence']) {
                $changed[$id] = (int)$seq['sequence'];
            }
        }

        if (empty($changed)) {
            return response()->json([
                'status'   => 'ok',
                'updated'  => 0,
                'skipped'  => count($ids),
                'message'  => 'Keine Änderungen nötig.',
            ]);
        }

        // Ein einziges Update mit CASE ... WHEN ... THEN ...
        // Optional in Chunks, falls sehr groß
        DB::transaction(function () use ($changed) {
            $chunks = array_chunk($changed, 800, true); // 800 pro Statement ist konservativ
            foreach ($chunks as $chunk) {
                $ids = array_keys($chunk);

                $caseParts = [];
                foreach ($chunk as $id => $seq) {
                    $caseParts[] = "WHEN {$id} THEN {$seq}";
                }
                $caseSql = implode(' ', $caseParts);
                $idList  = implode(',', $ids);

                $sql = "
                    UPDATE m_parameter
                    SET sequence = CASE id
                        {$caseSql}
                    END
                    WHERE id IN ({$idList})
                ";

                DB::update($sql);
            }
        });

        return response()->json([
            'status'  => 'ok',
            'updated' => count($changed),
            'skipped' => count($ids) - count($changed),
        ]);
    }

}


