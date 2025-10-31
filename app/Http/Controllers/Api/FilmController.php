<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FilmController extends Controller
{
    private function hasCol(string $table, string $col): bool
    {
        try { return Schema::hasColumn($table, $col); } catch (\Throwable $e) { return false; }
    }

    private function withTimestamps(string $table, array $data): array
    {
        if ($this->hasCol($table, 'created_at')) $data['created_at'] = now();
        if ($this->hasCol($table, 'updated_at')) $data['updated_at'] = now();
        return $data;
    }

    /**
     * GET /api/film
     * Optional query:
     *  - search=...
     *  - per_page / page (jika ingin paginate). Jika tidak, kirim list biasa.
     */
    public function index(Request $r)
    {
        $q = DB::table('film as f')
            ->leftJoin('genre as g', 'g.genre_id', '=', 'f.genre_id')
            ->select(
                'f.film_id','f.judul','f.durasi','f.sinopsis','f.genre_id',
                DB::raw('g.nama_genre as genre_name')
            );

        if ($r->filled('search')) {
            $s = '%'.$r->query('search').'%';
            $q->where(function($w) use ($s) {
                $w->where('f.judul','like',$s)
                  ->orWhere('f.sinopsis','like',$s);
            });
        }

        // kalau user minta paginate (ada page/per_page), pakai paginate
        if ($r->filled('page') || $r->filled('per_page')) {
            $per = max(1, (int)$r->query('per_page', 10));
            $data = $q->orderBy('f.film_id','desc')->paginate($per);
            return response()->json($data);
        }

        // default: list biasa
        $rows = $q->orderBy('f.film_id','desc')->get();
        return response()->json($rows);
    }

    /**
     * GET /api/film/{id}
     */
    public function show($id)
    {
        $row = DB::table('film as f')
            ->leftJoin('genre as g', 'g.genre_id', '=', 'f.genre_id')
            ->where('f.film_id', (int)$id)
            ->first([
                'f.film_id','f.judul','f.durasi','f.sinopsis','f.genre_id',
                DB::raw('g.nama_genre as genre_name')
            ]);

        if (!$row) return response()->json(['message'=>'Film tidak ditemukan'], 404);
        return response()->json($row);
    }

    /**
     * POST /api/film
     * body: judul (required), durasi (int), sinopsis (string), genre_id (int exists)
     */
    public function store(Request $r)
    {
        $r->validate([
            'judul'     => 'required|string|max:255',
            'durasi'    => 'nullable|integer|min:1',
            'sinopsis'  => 'nullable|string',
            'genre_id'  => 'nullable|integer|exists:genre,genre_id',
        ], [
            'genre_id.exists' => 'Genre tidak valid',
        ]);

        $data = $r->only(['judul','durasi','sinopsis','genre_id']);
        $data = $this->withTimestamps('film', $data);

        $id = DB::table('film')->insertGetId($data);

        return $this->show($id);
    }

    /**
     * PUT /api/film/{id}
     */
    public function update(Request $r, $id)
    {
        $row = DB::table('film')->where('film_id', (int)$id)->first();
        if (!$row) return response()->json(['message'=>'Film tidak ditemukan'], 404);

        $r->validate([
            'judul'     => 'sometimes|required|string|max:255',
            'durasi'    => 'sometimes|nullable|integer|min:1',
            'sinopsis'  => 'sometimes|nullable|string',
            'genre_id'  => 'sometimes|nullable|integer|exists:genre,genre_id',
        ], [
            'genre_id.exists' => 'Genre tidak valid',
        ]);

        $upd = $r->only(['judul','durasi','sinopsis','genre_id']);
        if ($this->hasCol('film','updated_at')) $upd['updated_at'] = now();

        DB::table('film')->where('film_id', (int)$id)->update($upd);

        return $this->show($id);
    }

    /**
     * DELETE /api/film/{id}
     */
    public function destroy($id)
    {
        try {
            $deleted = DB::table('film')->where('film_id', (int)$id)->delete();
            if (!$deleted) return response()->json(['message'=>'Film tidak ditemukan'], 404);
            return response()->json(['deleted' => true]);
        } catch (\Throwable $e) {
            // Bisa gagal karena FK (misal sudah dipakai jadwal)
            return response()->json([
                'deleted' => false,
                'message' => 'Gagal menghapus: kemungkinan film sudah dipakai di jadwal/relasi lain',
                'error'   => $e->getMessage(),
            ], 409);
        }
    }
}
