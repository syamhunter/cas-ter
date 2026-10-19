# Panduan Pengguna Aplikasi Kasir & Manajemen Usaha

Panduan ini menjelaskan penggunaan aplikasi dari penyiapan awal sampai kegiatan harian. Nama menu bisa berbeda sedikit menurut peran, paket, bidang usaha yang diaktifkan, dan ukuran layar.

## 1. Persiapan dan masuk aplikasi

### Jika aplikasi belum dipasang

1. Buka alamat aplikasi dan ikuti halaman **Instalasi Aplikasi**.
2. Siapkan database MySQL/MariaDB kosong, lalu masukkan host, port, nama database, user, dan kata sandi database sesuai panel server.
3. Masukkan kode instalasi yang disiapkan pada `storage/install-code.txt`.
4. Isi URL dasar hanya jika aplikasi dipasang dalam subfolder. Kosongkan jika aplikasi berada langsung di root domain.
5. Buat akun **Super Admin** dengan email dan kata sandi yang kuat.
6. Pilih apakah ingin membuat data demo. Jika dipilih, installer menampilkan akun pemilik dan kasir demo setelah pemasangan berhasil. Simpan sementara untuk uji coba, lalu ganti kata sandinya atau hapus data demo sebelum dipakai untuk operasional nyata.
7. Pastikan pemasangan berhasil dan installer terkunci. Jika terjadi kegagalan, lihat log server; jangan menghapus tabel secara manual sebelum membuat backup dan memastikan database yang benar.

Instalasi memerlukan PHP 8.1 atau lebih baru, `pdo_mysql`, dan folder penyimpanan yang dapat ditulis. Untuk penggunaan publik, HTTPS, backup, dan pemulihan data perlu dikonfigurasi pada hosting.

### Daftar usaha atau masuk

- Untuk membuat akun usaha sendiri, buka **Daftar usaha** dari halaman masuk. Isi nama pemilik, nama usaha, email login, kata sandi, cabang awal, bidang usaha utama, bidang tambahan bila diperlukan, dan paket yang tersedia.
- Untuk masuk, gunakan email dan kata sandi akun. Akun pemilik, manajer, kasir, dan super admin memiliki akses yang berbeda.
- Pemilik yang mempunyai beberapa usaha dapat berpindah usaha melalui pemilih usaha pada dashboard. Data katalog, kasir, dan transaksi tetap dipisahkan per usaha.
- Keluar menggunakan tombol **Keluar** pada bagian bawah sidebar. Jangan hanya menutup tab pada perangkat bersama.

## 2. Peran dan hak akses

| Peran | Kegunaan utama |
|---|---|
| **Pemilik** | Mengelola profil dan bidang usaha, usaha yang dimiliki, akun kasir, katalog, laporan cabang dan gabungan, audit aktivitas penting, shift, pengeluaran, pemasok, pembelian, stok opname, dan transfer cabang sesuai akses paket. |
| **Manajer** | Mengelola katalog dan operasional toko, pengeluaran, laporan, pembelian, persediaan, serta retur. Manajer tidak mengelola akun pemilik/usaha melalui dashboard pemilik. |
| **Kasir** | Membuka atau menggunakan shift kas bersama, melakukan transaksi, melihat transaksi dan laporan operasional yang tersedia, serta mencatat kas masuk/keluar sesuai tampilan. Tidak dapat mengelola katalog, pembelian, pengeluaran, atau memproses retur. |
| **Super Admin** | Mengelola akun usaha pelanggan, status langganan/paket, masa aktif, serta membuat atau mengaktifkan usaha. Gunakan peran ini hanya untuk administrasi layanan. |

Menu yang tidak termasuk hak akses atau bidang usaha aktif tidak akan ditampilkan atau tidak dapat digunakan.

## 3. Navigasi dan pengaturan dasar

- Sidebar berada di sisi kiri pada layar lebar dan menjadi navigasi ringkas pada layar kecil.
- Gunakan tombol ciutkan untuk menampilkan sidebar dengan ikon saja atau ikon beserta nama menu.
- Bagian bawah sidebar menampilkan akun aktif, tautan pengaturan yang sesuai peran, dan tombol keluar.
- Pastikan nama usaha, nama cabang, alamat, dan bidang usaha benar sebelum membuat transaksi. Nama usaha serta alamat cabang digunakan pada nota.
- Data transaksi, persediaan, pelanggan, dan laporan mengikuti usaha/cabang aktif. Periksa nama usaha dan cabang di bagian atas halaman sebelum menyimpan perubahan.

## 4. Panduan pemilik

Menu pemilik dipisahkan menjadi **Ringkasan usaha**, **Usaha & profil**, **Tim & kasir**, **Paket & billing**, dan **Bantuan**. Pemilihan usaha di header mengatur konteks profil, tim, dan ringkasan yang sedang dibuka; tiket dan billing berlaku untuk akun owner bersama.

### Dashboard dan profil usaha

1. Buka **Ringkasan usaha**.
2. Periksa status usaha/paket, batas penggunaan, aktivitas transaksi, cabang dan pengguna.
3. Pada **Profil usaha & bidang**, perbarui nama dan telepon usaha serta alamat cabang.
4. Pilih bidang usaha yang ingin diaktifkan di kasir, lalu simpan. Pilihan bidang dibatasi paket.
5. Untuk usaha lain, gunakan **Tambah usaha lain**, isi nama usaha, cabang pertama, telepon/alamat, dan bidangnya. Paket dan kuota tertentu digunakan bersama sesuai informasi pada halaman.
6. Jika memiliki beberapa usaha, pindah konteks melalui pemilih usaha sebelum mengelola profil, staf, atau data lainnya.

Pada **Paket & billing**, pemilik dapat melihat paket dan invoice untuk akun bersama seluruh usahanya, termasuk jatuh tempo dan masa tenggang 7 hari. Invoice dapat dicetak/disimpan sebagai PDF. Pembayaran dikonfirmasi administrator setelah bukti diverifikasi; pemilik tidak dapat mengubah status lunas sendiri dan akses tidak ditangguhkan otomatis oleh masa tenggang.

Pada **Bantuan**, owner dapat membuat tiket teknis, billing, akun, atau lainnya, mengaitkannya dengan invoice, dan membalas pada percakapan yang sama. Badge menunjukkan balasan baru saat halaman dimuat. Tiket selesai dapat dibuka kembali dengan mengirim balasan. Jangan menulis password, PIN, atau data kartu di pesan. Email otomatis, chat realtime, dan unggahan file belum tersedia.

### Mengelola akun kasir

1. Buka bagian **Akun kasir** pada dashboard pemilik.
2. Tambahkan nama, email login yang belum pernah digunakan, kata sandi awal, dan cabang yang boleh diakses.
3. Berikan kredensial kepada kasir secara pribadi; jangan gunakan satu akun bersama untuk beberapa orang.
4. Nonaktifkan akun yang tidak lagi boleh masuk. Gunakan fitur reset kata sandi bila kasir lupa kata sandi.
5. Periksa batas pengguna paket sebelum menambah staf. Setiap kasir harus memakai akun sendiri agar nama penanggung jawab transaksi tercatat.

## 5. Menyiapkan katalog, pelanggan, dan layanan

Buka **Manajemen toko**. Perubahan katalog berlaku untuk usaha aktif dan stok dicatat per cabang.

### Produk dan layanan

1. Pada **Katalog produk & layanan**, pilih **Tambah produk / layanan**.
2. Isi nama, bidang produk, cara menentukan harga, harga jual, harga modal, satuan, kategori opsional, SKU/barcode, dan peringatan stok minimum.
3. Untuk barang yang jumlah fisiknya perlu dipantau, aktifkan pelacakan stok.
4. Atur data khusus sesuai jenisnya:
   - **Retail/barang:** harga per unit dan informasi SKU/barcode.
   - **Menu F&B:** harga per porsi/unit dan informasi stasiun dapur atau estimasi persiapan bila digunakan.
   - **Apotek/obat:** nama generik, kekuatan, bentuk sediaan, kewajiban resep, serta pelacakan batch/kedaluwarsa bila diperlukan.
   - **Laundry:** model harga per kilogram atau per item, pilihan layanan, dan tambahan ekspres.
   - **Percetakan:** harga per meter persegi, bahan, lebar maksimum, luas minimum tagihan, serta pilihan finishing.
   - **Jasa:** harga tetap/per unit/per jam, durasi minimum, pembulatan durasi, dan kebutuhan memilih petugas.
5. Simpan produk. Pastikan modul bidang terkait aktif dan harga/satuan sudah diuji di kasir.
6. Untuk mengubah atau menonaktifkan produk, gunakan daftar katalog. Jangan membuat produk duplikat hanya untuk mengubah harga; periksa riwayat dan SKU terlebih dahulu.

Nama opsi dan atribut yang tersedia bergantung pada jenis produk. Harga final dihitung kembali oleh server saat checkout; angka sebelum checkout merupakan estimasi layar kasir.

### Stok awal, batch, dan penyesuaian

- Tambahkan stok awal melalui bagian penyesuaian/penerimaan stok di halaman Manajemen toko. Isi jumlah, alasan/catatan, dan informasi batch atau kedaluwarsa untuk barang yang memerlukannya.
- Untuk obat dengan pelacakan batch, masukkan nomor batch dan tanggal kedaluwarsa dengan benar. Sistem dapat menggunakan batch yang kedaluwarsanya paling dekat terlebih dahulu.
- Periksa cabang aktif sebelum menerima atau menyesuaikan stok.
- Gunakan **Pembelian & stok** untuk penerimaan pembelian formal, dan **Stok opname** untuk mencocokkan stok catatan dengan stok fisik. Hindari mencatat penerimaan yang sama lewat dua cara.

### Pelanggan

1. Pada bagian **Data pelanggan**, masukkan nama dan data kontak/alamat yang diperlukan.
2. Pelanggan yang tersimpan dapat dicari dari halaman kasir berdasarkan nama atau telepon.
3. Pemilihan pelanggan pada transaksi bersifat opsional; transaksi tanpa pilihan dicatat sebagai pelanggan umum.

### Antrean pengerjaan

Untuk bidang yang memiliki tahapan pengerjaan, buka bagian **Antrean pengerjaan**. Tinjau pesanan dan gunakan tombol tindakan untuk melanjutkan status. Perubahan status masuk ke riwayat transaksi.

## 6. Shift kas bersama

Satu cabang menggunakan **satu shift aktif untuk satu laci kas**. Beberapa kasir dapat bertransaksi pada shift yang sama; nama kasir tetap dicatat pada setiap transaksi. Checkout tidak dapat dilakukan sebelum ada shift terbuka.

### Membuka shift

1. Buka **Shift & kas** sebelum mulai menjual.
2. Masukkan uang/modal awal laci dan catatan bila perlu.
3. Pilih **Buka shift**. Kasir lain di cabang tersebut dapat menggunakan shift yang sama.
4. Periksa status **Shift aktif** di halaman kasir.

### Selama shift

- Catat tambahan uang sebagai **Kas masuk** dan pengambilan/pengeluaran laci sebagai **Kas keluar**, selalu isi alasan.
- Pengeluaran tunai yang dicatat pada halaman Pengeluaran juga masuk sebagai kas keluar shift secara otomatis.
- Saldo kas seharusnya memperhitungkan modal awal, pergerakan kas, pembayaran tunai bersih setelah kembalian, dan retur tunai.
- Pengeluaran non-tunai tidak mengurangi uang fisik dalam laci.

### Menutup shift

1. Hitung uang fisik di laci terlebih dahulu.
2. Masukkan nilai hitungan aktual dan catatan bila ada selisih.
3. Pilih **Tutup shift** dan konfirmasikan. Penutupan tidak dapat dibatalkan.
4. Sistem menghitung selisih: uang aktual dikurangi saldo seharusnya.
5. Hanya pembuka shift atau pemilik/manajer yang dapat menutup shift bersama. Setelah shift ditutup, buka shift baru untuk melanjutkan transaksi.

## 7. Melakukan transaksi di kasir

### Sebelum checkout

1. Pastikan usaha/cabang dan status shift benar.
2. Cari produk/layanan dengan nama atau SKU, atau pindai barcode. Pilih produk untuk memasukkannya ke keranjang.
3. Sesuaikan jumlah dan atribut yang diminta untuk jenis produk, misalnya ukuran/layanan, batch, resep, pilihan pengerjaan, atau catatan.
4. Pilih jenis pesanan yang sesuai. Untuk makan di tempat, pilih meja yang tersedia.
5. Tambahkan pelanggan bila diperlukan.
6. Masukkan diskon item atau diskon transaksi hanya jika akun memiliki izin. Pemilik/manajer memiliki hak pengelolaan; hak kasir bergantung pada izin yang diberikan.
7. Periksa subtotal, diskon, service charge, pajak, dan total. Angka pada layar adalah estimasi; server menghitung dan memvalidasi kembali saat transaksi disimpan.

### Pengaturan pajak cabang

Pemilik dapat mengatur tarif dan cara hitung pajak pada **Pemilik → Profil usaha & bidang** untuk cabang yang sedang dipilih:

- **Tidak dikenakan pajak**: tidak menambahkan atau mencatat pajak pada transaksi baru. Gunakan hanya bila usaha dan transaksi memang tidak wajib mengenakan pajak berdasarkan ketentuan yang berlaku. Status lembaga pendidikan tidak otomatis berarti semua transaksinya bebas pajak; pastikan dengan pihak akuntansi/pajak.
- **Tambahkan pajak ke total**: pajak dihitung di atas dasar transaksi setelah diskon dan service charge. Total checkout bertambah sebesar pajak; cocok jika harga ditampilkan sebelum pajak.
- **Pajak sudah termasuk harga**: jumlah pajak diuraikan dari harga transaksi dan tidak ditambahkan lagi ke total checkout; cocok jika harga yang ditampilkan ke pelanggan sudah termasuk pajak. Bagian pajak mengurangi pendapatan/margin usaha.

Untuk dua pilihan yang mengenakan pajak, kewajiban pencatatan dan penyetoran tetap berada pada usaha. Atur tarif sesuai ketentuan dan konsultasikan penerapannya dengan akuntan/petugas pajak. Pengaturan cabang berlaku untuk transaksi baru; transaksi lama mempertahankan cara hitung yang tercatat saat checkout.

### Pembayaran dan penyimpanan

1. Pilih satu atau beberapa metode pembayaran: tunai, QRIS, transfer, debit, kredit, atau lainnya.
2. Untuk pembayaran gabungan, tambahkan baris metode pembayaran dan masukkan nominal masing-masing. Isi nomor referensi bila diperlukan.
3. Pastikan jumlah pembayaran mencukupi total, lalu pilih **Checkout** satu kali.
4. Tunggu pesan berhasil sebelum mengulangi tindakan. Jika koneksi terputus setelah checkout, periksa **Transaksi** terlebih dahulu; permintaan yang sama dirancang agar tidak membuat transaksi ganda.
5. Pada nota hasil transaksi, pilih **Thermal 58 mm**, **Thermal 80 mm**, atau **A4**, kemudian pilih **Cetak**. Pilihan kertas disimpan di browser/perangkat yang sedang digunakan.
6. Pada dialog cetak browser, pilih printer yang sudah terpasang, ukuran kertas yang sama dengan pilihan nota, orientasi portrait, dan skala 100%/Actual size. Matikan header/footer browser bila muncul. Untuk kertas thermal, pastikan driver printer sudah disetel ke lebar roll yang benar dan margin sekecil yang didukung perangkat.

Cetak melalui dialog browser tetap tersedia sebagai fallback untuk printer thermal USB, jaringan, Bluetooth, atau printer biasa yang dipilih sistem operasi. Pada Chrome/Edge versi baru di localhost atau HTTPS, gunakan **Hubungkan USB** untuk printer USB berkelas printer yang mengekspos endpoint bulk-out, atau **Hubungkan serial** untuk memilih port printer/adapter serial dan baud rate yang sesuai. Setelah terhubung, pilih **Cetak ESC/POS USB** atau **Cetak ESC/POS**. Perangkat USB vendor-specific yang tidak mengekspos antarmuka printer-class/bulk-out dan printer tanpa port serial tidak didukung mode langsung; gunakan dialog Cetak browser. Mode langsung mengirim teks struk dan perintah potong standar (bila didukung printer), tetapi tidak membuka laci kas atau menjamin fitur khusus produsen. Uji hasil, encoding, baud rate, pemotong, serta dialog browser pada model printer dan perangkat kasir sebelum operasional.

### Menahan transaksi

- Pilih **Tahan** untuk menyimpan keranjang sementara ketika pelanggan belum siap membayar.
- Buka **Ditahan** untuk melanjutkan keranjang yang disimpan.
- Periksa keranjang sebelum checkout setelah melanjutkan transaksi yang ditahan.

### Ketentuan produk tertentu

- Obat yang ditandai wajib resep memerlukan ID resep yang valid dan belum digunakan.
- Barang dengan stok terlacak tidak dapat dijual melebihi persediaan yang tersedia.
- Produk dengan pelacakan batch mengikuti alokasi batch yang tersedia dan belum kedaluwarsa.
- Layanan laundry, percetakan, F&B, dan jasa dapat meminta informasi khusus sebelum checkout.

## 8. Transaksi, retur, dan laporan

### Riwayat transaksi

1. Buka **Transaksi** dari sidebar atau tombol di halaman kasir.
2. Pilih rentang tanggal dan, bila perlu, cari berdasarkan nomor invoice/ID transaksi.
3. Pilih transaksi untuk melihat rincian item dan pembayaran.
4. Daftar menampilkan paling banyak 100 transaksi terbaru untuk rentang pilihan.

### Penjualan tempo dan piutang pelanggan

- Hanya pemilik/manajer yang dapat memilih penjualan tempo saat checkout. Pilih pelanggan, isi tanggal jatuh tempo, dan catat pembayaran awal bila ada. Sisa tagihan akan muncul pada rincian transaksi.
- Untuk menerima pelunasan, buka rincian transaksi lalu gunakan **Terima pembayaran**. Pembayaran tunai memerlukan shift kasir yang aktif; metode non-tunai tidak memengaruhi kas fisik.
- Retur penjualan atas transaksi belum lunas mengurangi piutang terlebih dahulu. Hanya kelebihan nilai retur yang benar-benar dibayarkan kembali kepada pelanggan dicatat sebagai refund.
- Periksa sisa tagihan setelah setiap pembayaran atau retur. Transaksi historis tidak otomatis diubah menjadi piutang.

### Retur

- Retur hanya dapat diproses pemilik atau manajer pada rincian transaksi yang dapat diretur.
- Pilih item dan jumlah yang benar, metode pengembalian, lalu catatan bila diperlukan.
- Jumlah retur tidak boleh melebihi sisa jumlah yang belum diretur. Untuk stok terlacak, penerimaan stok mengikuti kebijakan sistem.
- Retur yang sudah dicatat memengaruhi jumlah pengembalian dana dan laporan. Verifikasi barang fisik dan nilai pengembalian sebelum menyimpan.
- Untuk transaksi lama, data retur terdahulu mungkin memakai perhitungan versi sebelumnya. Cocokkan transaksi dan pengembalian terdahulu dengan bukti pembayaran sebelum menjadikannya angka final.

### Laporan

1. Buka **Laporan**, tentukan tanggal mulai dan akhir, lalu pilih **Tampilkan laporan**. Tersedia pilihan cepat 7 hari, bulan ini, bulan lalu, dan tahun ini.
2. Tinjau ringkasan serta rincian penjualan harian, pembayaran, performa kasir, jenis pesanan, pengeluaran, jam penjualan, retur, stok menipis, pergerakan dan posisi stok, rekonsiliasi shift, penjualan produk, serta laba kotor estimasi.
3. Pemilik dapat melihat ringkasan periode seluruh cabang dan audit aktivitas penting. Pemilik/manajer dapat melihat umur piutang pelanggan. Angka cabang mengikuti tanggal transaksi/penerimaan; saldo utang/piutang dan aging menunjukkan posisi saat ini, bukan saldo historis. Audit hanya mencatat aktivitas yang dibuat setelah fitur ini aktif.
4. Pemilik/manajer juga dapat melihat rincian pembelian/penerimaan dan mengekspor data pembelian.
5. Pilih **CSV transaksi** atau **CSV pembelian** untuk spreadsheet.
6. Pilih **PDF laporan** atau **PDF pembelian** untuk membuka dialog cetak browser; pilih printer atau **Save as PDF / Simpan sebagai PDF**.

**Batasan interpretasi laporan:**

- Pajak adalah nilai yang tercatat saat transaksi (dengan mode termasuk harga atau ditambahkan ke total), bukan perhitungan pajak terutang atau faktur pajak resmi.
- Penjualan masuk ke periode berdasarkan tanggal transaksi; refund masuk ke periode berdasarkan tanggal retur. Penjualan item bersih dan laba kotor mengurangi refund item tanpa memasukkan pajak/biaya layanan; HPP dikreditkan kembali pada periode retur jika aplikasi mencatat barang tersebut dikembalikan ke stok.
- Ekspor CSV transaksi memisahkan baris **Penjualan** dan **Retur** berdasarkan tanggal masing-masing. Baris retur bernilai negatif pada kolom dampak penjualan bersih; jumlah uang refund tetap tercantum terpisah.
- Laba kotor mengalokasikan diskon transaksi ke item dan mengurangi diskon hanya sekali. Laba kotor dan laba bersih tetap estimasi, bukan laporan akuntansi resmi; retur barang yang tidak dapat dijual kembali dan biaya lain di luar menu Pengeluaran mungkin memerlukan penyesuaian pembukuan.
- Aplikasi belum menggantikan pembukuan penuh dan laporan pajak resmi. Validasi dengan akuntan/petugas pajak.
- Pesanan pembelian yang diterima setelah fitur utang aktif membuat tagihan pemasok. Tagihan pembelian lama tidak diimpor otomatis karena status pembayarannya tidak diketahui; cocokkan saldo pembuka secara terpisah.
- Laporan menampilkan saldo utang/kredit pemasok saat ini, pembayaran dan retur pembelian pada periode laporan, serta umur utang saat ini. Saldo tersebut bukan saldo historis per tanggal laporan.
- Pembayaran tunai pemasok memerlukan shift aktif. Kredit dari retur pemasok ditampilkan, tetapi belum otomatis dialokasikan ke tagihan lain. Pajak masukan resmi belum dikelola.
- Nilai persediaan saat ini menggunakan biaya modal varian sekarang, bukan biaya historis pada akhir periode.
- Laba kotor adalah estimasi berdasarkan data modal produk. Pastikan harga modal, diskon, retur, dan penerimaan stok akurat.
- Laporan utama mencakup cabang aktif sesuai konteks; tabel gabungan hanya terlihat oleh pemilik dan merangkum semua cabang aktif pada usaha yang dipilih.

## 9. Pengeluaran

1. Pemilik/manajer membuka **Pengeluaran**.
2. Isi tanggal, kategori, nominal, metode pembayaran, dan keterangan.
3. Tanggal pengeluaran tidak boleh melewati hari ini.
4. Pengeluaran tunai hanya bisa dicatat ketika shift cabang terbuka dan otomatis menambah kas keluar pada shift aktif.
5. Pilih rentang periode untuk melihat total dan riwayat.
6. Catatan pengeluaran bersifat permanen sebagai jejak audit; pastikan tanggal, metode, nominal, dan keterangan benar sebelum menyimpan.

## 10. Pembelian dan pengelolaan persediaan

Fitur berikut tersedia untuk pemilik/manajer:

### Pemasok dan pesanan pembelian

1. Pada **Shift & kas** atau halaman operasional, buka bagian **Pemasok & pembelian**.
2. Tambahkan pemasok beserta kontak yang diperlukan.
3. Buat pesanan pembelian untuk cabang aktif, pilih pemasok, produk/varian, jumlah, dan biaya unit. Untuk produk batch, isi nomor batch dan tanggal kedaluwarsa yang benar.
4. Pesanan yang dibuat belum menambah stok.
5. Bila pembelian belum dibayar, isi tanggal jatuh tempo sebelum menyimpan pesanan. Tagihan dibuat saat pesanan diterima.
6. Saat barang benar-benar tiba, buka pesanan dan pilih **Terima**. Penerimaan menambah stok, mencatat pergerakan persediaan, memperbarui harga modal rata-rata, dan mencatat tagihan pemasok.
7. Gunakan pembayaran pemasok untuk mencatat pelunasan penuh atau sebagian. Pembayaran tunai memerlukan shift aktif.
8. Untuk mengembalikan barang, pilih retur pembelian, pemasok/pesanan, dan jumlah setelah memeriksa stok fisik. Retur mengurangi stok dan saldo tagihan atau membuat kredit pemasok.
9. Pesanan hanya dapat diterima satu kali. Cocokkan jumlah dan kondisi barang sebelum mengonfirmasi. Retur pembelian historis tanpa batch penerimaan terlacak tidak dapat diproses melalui alur ini.

### Stok opname

1. Buat stok opname untuk membuat snapshot pembanding.
2. Hitung stok fisik, isi jumlah yang dihitung untuk setiap item/batch, dan periksa selisih.
3. Tinjau semua jumlah sebelum mem-posting. Posting menerapkan penyesuaian ke stok; tindakan ini sebaiknya dilakukan setelah hitung fisik selesai.
4. Simpan catatan untuk menjelaskan hasil audit atau koreksi.

### Transfer antar-cabang

1. Dari cabang sumber, pilih cabang tujuan, produk, jumlah, dan catatan. Pilih batch yang benar bila produk melacak batch.
2. Saat transfer dikirim, stok cabang asal langsung berkurang dan transfer berstatus **Dalam perjalanan**.
3. Pada cabang tujuan, cocokkan barang fisik lalu pilih **Konfirmasi terima**. Stok cabang tujuan bertambah setelah penerimaan dikonfirmasi.
4. Jangan mengonfirmasi barang yang belum diterima. Transfer yang belum diterima tetap tercatat dalam perjalanan.

## 11. Pengelolaan layanan langganan (Super Admin)

Bagian ini hanya untuk operator layanan aplikasi:

- Navigasi Super Admin memisahkan **Ringkasan**, **Pelanggan**, **Paket**, **Billing**, **Aktivitas**, **Dukungan**, dan **Akun Owner**. Daftar pelanggan, pembayaran, aktivitas, tiket, serta akun owner dimuat bertahap dengan pagination.
- **Pelanggan** menyediakan pencarian/filter, detail usaha, cabang, kuota paket, status, masa berlaku, dan pemakaian transaksi paket bulan berjalan.
- **Tambah pelanggan** membuat usaha/pemilik awal, menetapkan paket, lama masa trial/aktif, modul, cabang pertama, pajak, dan service charge F&B.
- Untuk mengganti paket pelanggan, pilih paket baru pada baris usaha lalu tekan **Ubah**. Paket baru harus aktif. Jika akun pemilik memiliki beberapa usaha, perubahan berlaku pada seluruh usaha di akun tersebut; modul yang tidak termasuk paket tidak tersedia selama paket itu digunakan.
- **Paket** memungkinkan operator menambah/mengedit harga, kuota cabang/pengguna/transaksi, dan modul, serta menonaktifkan paket. Paket tidak dihapus permanen agar relasi pelanggan tetap terjaga.
- **Billing** mengelola invoice manual dan invoice otomatis, bukan payment gateway. Admin dapat membuat invoice dari detail usaha. Generator otomatis membuat invoice saat langganan aktif/trial berharga mendekati tanggal akhir (dalam 14 hari), dengan harga paket sebagai snapshot dan tanpa invoice periode yang tumpang tindih.
- Untuk mengaktifkan generator otomatis di Windows, buat task harian di Task Scheduler yang menjalankan `C:\xampp-new\php\php.exe` dengan argumen `C:\xampp-new\htdocs\core\tools\generate-subscription-invoices.php`. Sesuaikan kedua path jika lokasi XAMPP berbeda. Jalankan task dengan akun Windows yang dapat membaca `.env` dan mengakses database.
- Owner dapat mengunggah bukti transfer PDF/JPG/PNG maksimal 5 MB dari **Paket & billing**. File disimpan di storage privat dan hanya dapat dibuka oleh Owner pemilik akun atau Super Admin.
- Admin memeriksa bukti dari antrean Billing lalu menyetujui atau menolak dengan catatan. Hanya setelah disetujui, invoice ditandai lunas dan masa aktif diperpanjang sampai akhir periode invoice tanpa memundurkan masa aktif yang masih lebih panjang. Upload bukti atau transfer tidak mengaktifkan layanan otomatis sebelum verifikasi.
- Invoice yang melewati jatuh tempo ditandai **Terlambat** dan mendapat masa tenggang 7 hari. Sistem belum menangguhkan akses secara otomatis; admin perlu meninjau status dan mengambil tindakan sesuai kebijakan layanan. Ledger pembayaran lama tetap tersedia terpisah dari invoice baru.
- **Suspend/Aktifkan** mengubah status usaha. **Perpanjang langganan** memperbarui masa aktif secara manual.
- **Dukungan** adalah inbox tiket Owner. Admin dapat membalas, mengubah status tiket, dan melihat invoice yang ditautkan. Status **Menunggu owner** berarti giliran owner membalas; status selesai dapat dibuka kembali oleh balasan owner. Pesan baru menambah indikator unread di portal kedua pihak.
- **Akun Owner** menyediakan reset password dengan password sementara; sampaikan melalui kanal pribadi dan minta owner menggantinya setelah masuk. **Aktivitas** menyimpan jejak perubahan administratif.
- Notifikasi tiket saat ini berupa indikator di dalam aplikasi. Email otomatis, chat realtime, dan unggahan lampiran belum tersedia; jangan kirim password, PIN, atau data kartu melalui tiket.
- Pastikan email dan paket benar sebelum membuat pelanggan. Berikan kata sandi awal secara aman dan minta pemilik menyimpannya secara pribadi.

## 12. Kebiasaan operasional yang disarankan

- Gunakan satu akun untuk satu orang; jangan berbagi kredensial kasir.
- Buka shift sebelum transaksi pertama dan tutup setelah uang fisik direkonsiliasi.
- Catat setiap kas masuk/keluar dengan alasan yang jelas.
- Perbarui harga modal ketika menerima barang, khususnya untuk laporan laba kotor.
- Gunakan penerimaan pembelian untuk pembelian pemasok dan stok opname untuk koreksi hasil hitung agar jejak stok tidak membingungkan.
- Periksa nama usaha/cabang sebelum membuat transaksi, pembelian, retur, atau transfer.
- Ekspor laporan berkala dan ikuti prosedur backup server yang ditetapkan pengelola hosting.
- Jangan memasukkan data pelanggan atau transaksi demo sebagai data operasional sebenarnya.

## 13. Backup dan pemulihan

Skrip administrator Windows tersedia di `tools/backup.ps1` dan `tools/restore.ps1`. Skrip membutuhkan PowerShell, `mysqldump.exe`/`mysql.exe` dari instalasi MySQL/MariaDB, serta `.env` yang valid. Secara default, file backup disimpan di `%USERPROFILE%\POS-Backups`, di luar webroot. Backup berisi seluruh database aplikasi dan `storage/uploads`; backup tidak memuat kode aplikasi, `.env`, cache, log, atau sesi.

1. Jadwalkan backup di luar jam sibuk atau hentikan sementara perubahan transaksi/upload selama proses agar dump database dan file unggahan tetap selaras. Jalankan `.\tools\backup.ps1` dari folder aplikasi. Jika MySQL tidak berada di folder XAMPP standar, tambahkan `-MySqlBinPath "C:\path\ke\mysql\bin"`.
2. Salin folder backup yang dihasilkan ke media/lokasi aman yang terpisah dari server. Dump database berisi data semua usaha pada database itu; batasi akses dan enkripsi media sesuai kebijakan organisasi.
3. Untuk pemulihan, hentikan penggunaan aplikasi dan uji dahulu pada lingkungan staging dengan versi aplikasi serta database yang sesuai.
4. Jalankan `.\tools\restore.ps1 -BackupPath "$env:USERPROFILE\POS-Backups\pos-backup-YYYYMMDD-HHMMSS" -ConfirmDatabase "NAMA_DB"`. Ganti path dan nama dengan nilai yang tepat dari folder backup dan `DB_NAME` di `.env`.
5. Skrip meminta konfirmasi nama database, memeriksa hash dump, dan membuat backup kondisi saat ini sebelum mengimpor. Pemulihan mengganti isi database yang dipilih. Folder upload lama dipertahankan sebagai `storage/uploads-before-restore-*`; jangan hapus sampai aplikasi dan data hasil pemulihan diverifikasi.

Jangan menjalankan restore pada database produksi tanpa persetujuan administrator dan backup yang sudah diverifikasi. Skrip ini ditujukan untuk server Windows/XAMPP atau server yang memiliki executable MySQL kompatibel; hosting yang tidak menyediakan akses PowerShell/MySQL CLI harus memakai prosedur backup/restore resmi penyedia hosting.

## 14. Pemecahan masalah umum

| Gejala | Yang perlu diperiksa |
|---|---|
| Tidak bisa melakukan checkout | Pastikan cabang yang benar dipilih, shift bersama sedang terbuka, langganan aktif, produk/modul aktif, stok cukup, dan pembayaran mencukupi. |
| Produk tidak terlihat di kasir | Periksa status aktif produk, bidang usaha yang aktif, cabang, barcode/SKU, serta pengaturan pelacakan stok. |
| Obat meminta resep | Produk ditandai wajib resep. Gunakan ID resep valid yang belum terpakai dan milik cabang yang sesuai. |
| Tidak bisa membuka shift | Periksa apakah sudah ada shift aktif di cabang tersebut. Satu laci hanya memakai satu shift aktif. |
| Tidak bisa menutup shift | Kasir hanya dapat menutup shift yang dibukanya; pemilik/manajer dapat menutup shift cabang. |
| Selisih saldo kas | Cocokkan modal awal, transaksi tunai setelah kembalian, kas masuk/keluar, retur tunai, dan uang fisik. Jangan mengubah hitungan untuk memaksa selisih menjadi nol. |
| Laporan kosong atau angka berbeda | Periksa periode, usaha/cabang aktif, status transaksi, tanggal penerimaan pembelian, dan apakah filter/rentang periode sesuai. |
| Login gagal atau kata sandi terlupa | Pastikan email yang digunakan benar. Hubungi pemilik untuk reset kata sandi kasir, atau administrator layanan untuk akun pemilik/super admin. |
| Instalasi database gagal | Pastikan host/port/user/password sesuai panel server, user memiliki izin pada database yang dipilih, dan database kosong. Jangan kirim kata sandi melalui percakapan atau tiket publik. |
| Halaman gagal dimuat | Catat waktu dan halaman yang bermasalah, lalu minta administrator memeriksa log server. Jangan tampilkan isi `.env` atau log yang berisi informasi sensitif kepada pihak lain. |
