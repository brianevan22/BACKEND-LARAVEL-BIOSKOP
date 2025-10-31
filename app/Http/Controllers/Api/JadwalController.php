<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class JadwalController extends Controller
{
    private const MIN_SEATS_PER_STUDIO = 15; // minimal 15 kursi / studio

    private function hasCol(string $table, string $col): bool
    {
        try { return Schema::hasColumn($table, $col); } catch (\Throwable $e) { return false; }
    }

    private function studioPrefix(int $studioId): string
    {
        // 1->A, 2->B, 3->C, ...
        $ord = ord('A') + max(0, $studioId - 1);
        $ord = min($ord, ord('Z'));
        return chr($ord);
    }

    private function defaultPrice(int $studioId): int
    {
        // Ubah mapping jika perlu
        $map = [1 => 50000, 2 => 100000, 3 => 75000];
        return $map[$studioId] ?? 50000;
    }

    /** Cari grup slot dan jadwal kanonik (id terkecil) untuk sebuah jadwal */
    private function canonicalFor(int $jadwalId): array
    {
        $jd = DB::table('jadwal')->where('jadwal_id', $jadwalId)->first();
        if (!$jd) abort(response()->json(['message'=>'Jadwal tidak ditemukan'], 404));

        // Definisi SLOT: studio_id + tanggal + jam_mulai
        $ids = DB::table('jadwal')
            ->where('studio_id', $jd->studio_id)
            ->where('tanggal',   $jd->tanggal)
            ->where('jam_mulai', $jd->jam_mulai)
            ->orderBy('jadwal_id')
            ->pluck('jadwal_id')
            ->all();

        if (empty($ids)) abort(response()->json(['message'=>'Jadwal tidak ditemukan'], 404));

        $canonId = (int)min($ids);
        return [$canonId, $ids, $jd];
    }

    /**
     * Pastikan minimal 15 kursi tercatat pada studio:
     * A1..A15 (studio 1), B1..B15 (studio 2), dst.
     */
    private function ensureStudioSeats(int $studioId): void
    {
        $prefix   = $this->studioPrefix($studioId);
        $minCount = self::MIN_SEATS_PER_STUDIO;

        $existing = DB::table('kursi')
            ->where('studio_id', $studioId)
            ->orderBy('kursi_id')
            ->pluck('nomor_kursi')
            ->toArray();

        $have = count($existing);
        if ($have >= $minCount) return;

        $toInsert = [];
        for ($n = $have + 1; $n <= $minCount; $n++) {
            $toInsert[] = [
                'nomor_kursi' => $prefix . $n,
                'studio_id'   => $studioId,
            ];
        }
        if (!empty($toInsert)) {
            DB::table('kursi')->insert($toInsert);
        }
    }

    // ================== API ==================

    // GET /api/jadwal?film_id=2
    public function index(Request $r)
    {
        $q = DB::table('jadwal as j')
            ->leftJoin('film as f',   'f.film_id',   '=', 'j.film_id')
            ->leftJoin('studio as s', 's.studio_id', '=', 'j.studio_id')
            ->select(
                'j.jadwal_id','j.film_id','j.studio_id','j.tanggal','j.jam_mulai','j.jam_selesai',
                DB::raw('f.judul as film_judul'),
                DB::raw('s.nama_studio as nama_studio')
            );

        if ($r->filled('film_id')) {
            $q->where('j.film_id', (int)$r->input('film_id'));
        }

        $rows = $q->orderBy('j.tanggal')->orderBy('j.jam_mulai')->get();

        return response()->json($rows);
    }

    // POST /api/jadwal
    public function store(Request $r)
    {
        $r->validate([
            'film_id'     => 'required|integer',
            'studio_id'   => 'required|integer',
            'tanggal'     => 'required|date',
            'jam_mulai'   => 'required|date_format:H:i:s',
            'jam_selesai' => 'required|date_format:H:i:s',
        ]);

        $data = $r->only(['film_id','studio_id','tanggal','jam_mulai','jam_selesai']);
        if ($this->hasCol('jadwal','created_at')) $data['created_at'] = now();
        if ($this->hasCol('jadwal','updated_at')) $data['updated_at'] = now();

        $id = DB::table('jadwal')->insertGetId($data);
        $row = DB::table('jadwal')->where('jadwal_id',$id)->first();

        return response()->json($row, 201);
    }

    // GET /api/jadwal/by-film/{film}
    public function getByFilm($filmId)
    {
        $rows = DB::table('jadwal as j')
            ->leftJoin('film as f',   'f.film_id',   '=', 'j.film_id')
            ->leftJoin('studio as s', 's.studio_id', '=', 'j.studio_id')
            ->where('j.film_id', (int)$filmId)
            ->orderBy('j.tanggal')
            ->orderBy('j.jam_mulai')
            ->get([
                'j.jadwal_id','j.film_id','j.studio_id','j.tanggal','j.jam_mulai','j.jam_selesai',
                DB::raw('f.judul as film_judul'),
                DB::raw('s.nama_studio as nama_studio')
            ]);

        return response()->json($rows);
    }

    /**
     * GET /api/jadwal/{jadwal}/seats
     * - Jadikan slot sinkron: pakai jadwal kanonik (min id untuk kombinasi studio+tanggal+jam_mulai)
     * - Jamin kursi studio minimal 15, seed tiket jika belum ada
     * - Normalisasi harga + status
     */
    public function getSeats($jadwalId, Request $r)
    {
        [$canonId, $_group, $jd] = $this->canonicalFor((int)$jadwalId);

        $studioId     = (int)$jd->studio_id;
        $hargaDefault = $this->defaultPrice($studioId);

        return DB::transaction(function () use ($canonId, $studioId, $hargaDefault) {

            // 1) Jamin kursi studio ada 15
            $this->ensureStudioSeats($studioId);

            // 2) Ambil semua kursi studio
            $kursi = DB::table('kursi')
                ->where('studio_id', $studioId)
                ->orderBy('kursi_id')
                ->get(['kursi_id','nomor_kursi']);

            // 3) Seed / repair tiket per kursi di JADWAL KANONIK
            foreach ($kursi as $k) {
                $t = DB::table('tiket')
                    ->where('jadwal_id', $canonId)
                    ->where('kursi_id',  $k->kursi_id)
                    ->lockForUpdate()
                    ->first();

                if (!$t) {
                    $ins = [
                        'jadwal_id' => $canonId,
                        'kursi_id'  => $k->kursi_id,
                        'harga'     => $hargaDefault,
                        'status'    => 'tersedia',
                    ];
                    if ($this->hasCol('tiket','created_at')) $ins['created_at'] = now();
                    if ($this->hasCol('tiket','updated_at')) $ins['updated_at'] = now();
                    DB::table('tiket')->insert($ins);
                } else {
                    // normalisasi status + harga (anti nol)
                    $status = strtolower((string)($t->status ?? 'tersedia'));
                    $status = in_array($status, ['terjual','sold']) ? 'terjual' : 'tersedia';

                    $upd = [];
                    if ($status !== $t->status) $upd['status'] = $status;

                    $hargaNow = isset($t->harga) ? (float)$t->harga : 0.0;
                    if ($hargaNow <= 0) $upd['harga'] = $hargaDefault;

                    if (!empty($upd)) {
                        if ($this->hasCol('tiket','updated_at')) $upd['updated_at'] = now();
                        DB::table('tiket')->where('tiket_id', $t->tiket_id)->update($upd);
                    }
                }
            }

            // 4) Kembalikan kursi + status + harga numerik (berdasar JADWAL KANONIK)
            $rows = DB::table('kursi as k')
                ->leftJoin('tiket as t', function($j) use ($canonId) {
                    $j->on('t.kursi_id', '=', 'k.kursi_id')
                      ->where('t.jadwal_id', '=', $canonId);
                })
                ->where('k.studio_id', $studioId)
                ->orderBy('k.kursi_id')
                ->get([
                    'k.kursi_id',
                    DB::raw('COALESCE(k.nomor_kursi, CONCAT("S", k.kursi_id)) as nama_kursi'),
                    DB::raw('(CASE WHEN t.harga IS NULL OR t.harga <= 0 THEN '.$hargaDefault.' ELSE t.harga END) + 0 as harga'),
                    DB::raw('CASE LOWER(COALESCE(t.status,"tersedia"))
                                WHEN "sold" THEN "terjual"
                                WHEN "terjual" THEN "terjual"
                                ELSE "tersedia"
                             END as status'),
                    DB::raw('t.tiket_id as tiket_id'),
                ]);

            // pastikan numeric & alias
            $rows = $rows->map(function ($r) {
                $num = (int) round((float) ($r->harga ?? 0));
                $r->harga     = $num;
                $r->price     = $num;
                $r->harga_int = $num;
                $r->status    = strtolower($r->status ?? 'tersedia') === 'terjual' ? 'terjual' : 'tersedia';
                return $r;
            });

            return response()->json($rows->values());
        });
    }

    /**
     * DELETE /api/jadwal/{id}
     * - Non-kanonik: cukup hapus baris jadwal
     * - Kanonik:
     *    - force=false: bila ada transaksi → 409. Jika tidak, hapus tiket (slot kosong) lalu hapus jadwal ini.
     *    - force=true : hapus semua detail_transaksi & tiket slot ini, lalu hapus SEMUA jadwal di slot.
     */
    public function destroy(Request $r, $id)
    {
        [$canonId, $groupIds, $jd] = $this->canonicalFor((int)$id);
        $isCanonical = ((int)$id === $canonId);

        if (!$isCanonical) {
            DB::table('jadwal')->where('jadwal_id', (int)$id)->delete();
            return response()->json(['deleted'=>true, 'canonical'=>false]);
        }

        $force = $r->boolean('force') || in_array($r->query('force'), ['1','true'], true);

        return DB::transaction(function () use ($canonId, $groupIds, $force) {

            $tiketIds = DB::table('tiket')->where('jadwal_id',$canonId)->pluck('tiket_id')->all();

            if (!$force) {
                $hasDetail = !empty($tiketIds)
                          && DB::table('detail_transaksi')->whereIn('tiket_id',$tiketIds)->exists();
                if ($hasDetail) {
                    return response()->json(['message'=>'Tidak bisa menghapus: sudah ada transaksi untuk slot ini'], 409);
                }
                DB::table('tiket')->where('jadwal_id',$canonId)->delete();
                DB::table('jadwal')->where('jadwal_id',$canonId)->delete();
                return response()->json(['deleted'=>true, 'canonical'=>true]);
            }

            // force = true → bersihkan slot
            if (!empty($tiketIds)) {
                DB::table('detail_transaksi')->whereIn('tiket_id',$tiketIds)->delete();
            }
            DB::table('tiket')->where('jadwal_id',$canonId)->delete();
            DB::table('jadwal')->whereIn('jadwal_id',$groupIds)->delete();

            return response()->json(['deleted'=>true, 'canonical'=>true, 'slot_cleared'=>true]);
        });
    }
}
