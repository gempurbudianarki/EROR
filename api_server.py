# api_server.py (Server API Python dengan Flask + CORS)
# PENYESUAIAN: Feedback lebih spesifik untuk kualitas gambar (gelap/buram)

from flask import Flask, request, jsonify
from flask_cors import CORS
import face_recognition
import base64
import numpy as np
import cv2
import os
import uuid
import logging
import json
from scipy.spatial import distance as dist

app = Flask(__name__)
CORS(app)

logging.basicConfig(level=logging.DEBUG, format='%(asctime)s %(levelname)s:%(lineno)d: %(message)s')

BASE_PROJECT_DIR = os.path.dirname(os.path.abspath(__file__))
UPLOAD_DIR_PYTHON = os.path.join(BASE_PROJECT_DIR, "uploads", "registrasi_foto")

try:
    os.makedirs(UPLOAD_DIR_PYTHON, exist_ok=True)
    app.logger.info(f"Direktori upload API Python: {UPLOAD_DIR_PYTHON}")
except OSError as e:
    app.logger.error(f"Gagal membuat direktori upload {UPLOAD_DIR_PYTHON}: {e.strerror}")

def check_image_quality(image_bgr, frame_index="N/A"):
    gray = cv2.cvtColor(image_bgr, cv2.COLOR_BGR2GRAY)
    laplacian_var = cv2.Laplacian(gray, cv2.CV_64F).var()
    blur_threshold = 10.0 
    app.logger.debug(f"Frame [{frame_index}] Quality - Laplacian Variance: {laplacian_var:.2f} (Th: >{blur_threshold})")
    if laplacian_var < blur_threshold:
        # Pesan lebih ramah pengguna
        msg = (f"Gambar terlihat buram (ketajaman: {laplacian_var:.0f}, min: {blur_threshold:.0f}). Pastikan fokus kamera baik.")
        app.logger.warning(msg)
        return False, msg
        
    min_brightness_threshold = 40 
    max_brightness_threshold = 220
    mean_brightness = np.mean(gray)
    app.logger.debug(f"Frame [{frame_index}] Quality - Mean Brightness: {mean_brightness:.2f} (Ideal: {min_brightness_threshold}-{max_brightness_threshold})")
    if mean_brightness < min_brightness_threshold:
        msg = (f"Pencahayaan terlalu gelap (kecerahan: {mean_brightness:.0f}, min: {min_brightness_threshold:.0f}). Coba tambah cahaya.")
        app.logger.warning(msg)
        return False, msg
    if mean_brightness > max_brightness_threshold:
        msg = (f"Pencahayaan terlalu terang (kecerahan: {mean_brightness:.0f}, maks: {max_brightness_threshold:.0f}). Kurangi cahaya atau hindari backlight.")
        app.logger.warning(msg)
        return False, msg
        
    return True, "Kualitas gambar baik." # Pesan sukses tidak perlu ditampilkan ke user biasanya


def eye_aspect_ratio(eye_landmarks):
    A = dist.euclidean(eye_landmarks[1], eye_landmarks[5])
    B = dist.euclidean(eye_landmarks[2], eye_landmarks[4])
    C = dist.euclidean(eye_landmarks[0], eye_landmarks[3])
    if C < 1e-3: return 0.35
    ear = (A + B) / (2.0 * C)
    return ear

EYE_AR_THRESH = 0.22
EYE_AR_OPEN_THRESH = 0.24
EYE_AR_CONSEC_FRAMES = 1
MIN_BLINKS_REQUIRED = 1

def check_blinks(frame_analysis_results_list): # Menerima frame yang sudah pasti valid (kualitas OK, 1 wajah)
    if not frame_analysis_results_list: # Seharusnya tidak terjadi jika logika di detect_and_store_face benar
        return False, "Tidak ada frame valid untuk analisis kedipan.", []

    blink_counter = 0
    consecutive_closed_frames = 0
    potential_open_eye_frames_info = []
    app.logger.debug(f"[BLINK_DETECT] Memulai analisis kedipan pada {len(frame_analysis_results_list)} frame valid. Target Blinks: {MIN_BLINKS_REQUIRED}.")
    
    for analysis_result in frame_analysis_results_list: # Semua frame di sini sudah quality_ok dan face_found
        original_index = analysis_result.get('original_index', 'N/A')
        left_ear = analysis_result.get('left_ear')
        right_ear = analysis_result.get('right_ear')

        if left_ear is None or right_ear is None: # Seharusnya tidak terjadi jika face_found=True dan landmarks ada
            avg_ear = EYE_AR_THRESH - 0.01 
            app.logger.warning(f"[BLINK_DETECT] Frame Indeks {original_index}: EAR tidak valid, dianggap tertutup.")
        else:
            avg_ear = (left_ear + right_ear) / 2.0
        
        app.logger.debug(f"[BLINK_DETECT] Frame Indeks {original_index}: Avg EAR: {avg_ear:.4f}")

        if avg_ear < EYE_AR_THRESH:
            consecutive_closed_frames += 1
            app.logger.debug(f"[BLINK_DETECT]   -> Frame Indeks {original_index}: Mata TERTUTUP (EAR {avg_ear:.4f} < {EYE_AR_THRESH}). Consecutive_closed: {consecutive_closed_frames}")
        else:
            app.logger.debug(f"[BLINK_DETECT]   -> Frame Indeks {original_index}: Mata TERBUKA (EAR {avg_ear:.4f} >= {EYE_AR_THRESH}). Consecutive_closed sebelumnya: {consecutive_closed_frames}")
            if consecutive_closed_frames >= EYE_AR_CONSEC_FRAMES:
                blink_counter += 1
                app.logger.info(f"[BLINK_DETECT] KEDIPAN Terdeteksi berakhir di Frame Indeks {original_index}! Total Blinks: {blink_counter}")
            consecutive_closed_frames = 0
            if avg_ear >= EYE_AR_OPEN_THRESH: 
                potential_open_eye_frames_info.append({
                    'original_index': analysis_result.get('original_index_in_cache', original_index), # Gunakan index cache
                    'avg_ear': avg_ear
                })
                app.logger.debug(f"[BLINK_DETECT]     -> Frame Indeks {original_index} -> KANDIDAT MATA TERBUKA LEBAR (EAR: {avg_ear:.4f} >= {EYE_AR_OPEN_THRESH}).")
            else:
                app.logger.debug(f"[BLINK_DETECT]     -> Frame Indeks {original_index}: Mata terbuka (EAR: {avg_ear:.4f}) TAPI di bawah OpenThresh ({EYE_AR_OPEN_THRESH}).")

    if consecutive_closed_frames >= EYE_AR_CONSEC_FRAMES:
        blink_counter += 1
        app.logger.info(f"[BLINK_DETECT] End of frames check: KEDIPAN Terdeteksi di akhir! (Total Blinks: {blink_counter})")

    app.logger.info(f"[BLINK_DETECT] Analisis kedipan selesai. Total kedipan terdeteksi: {blink_counter}. Dibutuhkan minimal: {MIN_BLINKS_REQUIRED}.")
    
    if not potential_open_eye_frames_info:
        app.logger.warning("[BLINK_DETECT] Tidak ada frame yang memenuhi syarat mata terbuka lebar (potential_open_eye_frames_info kosong) setelah loop.")
    else:
        potential_open_eye_frames_info.sort(key=lambda x: x['avg_ear'], reverse=True)
        candidate_frame_details_str = ", ".join([f"IdxCache:{info['original_index']}(EAR:{info['avg_ear']:.3f})" for info in potential_open_eye_frames_info])
        app.logger.debug(f"[BLINK_DETECT] Kandidat frame mata terbuka lebar (diurutkan EAR desc): [{candidate_frame_details_str}]")

    if blink_counter >= MIN_BLINKS_REQUIRED:
        return True, f"Deteksi keaktifan ({blink_counter} kedipan) berhasil.", potential_open_eye_frames_info
    else:
        # Pesan ini akan ditampilkan jika ada frame valid tapi kedipan tidak cukup
        return False, f"Terdeteksi {blink_counter} kedipan (min. {MIN_BLINKS_REQUIRED}). Mohon berkedip lebih jelas dan alami beberapa kali.", []


@app.route('/api/detect_and_store_face', methods=['POST'])
def detect_and_store_face_endpoint():
    app.logger.info("Endpoint /api/detect_and_store_face (multi-frame) diakses.")
    if 'frames_base64_json' not in request.form or 'username' not in request.form:
        return jsonify({"success": False, "message": "Data input tidak lengkap (membutuhkan data frame dan username)."}), 400
    
    frames_base64_json_str = request.form['frames_base64_json']
    username = request.form['username']
    safe_username_for_file = "".join(c if c.isalnum() else "_" for c in username)
    app.logger.info(f"Menerima permintaan multi-frame untuk username: {username} (safe: {safe_username_for_file})")

    try:
        frames_base64_list = json.loads(frames_base64_json_str)
        if not isinstance(frames_base64_list, list) or len(frames_base64_list) < 5 :
            return jsonify({"success": False, "message": "Format data frame tidak valid atau jumlah frame terlalu sedikit (minimal 5)."}), 400
    except json.JSONDecodeError:
        return jsonify({"success": False, "message": "Format data frame (JSON) tidak valid."}), 400

    processed_frames_analysis_list = []
    bgr_images_cache = {} 
    
    for i, image_base64_str in enumerate(frames_base64_list):
        app.logger.debug(f"Memproses Frame Asli Indeks {i}...")
        current_analysis_data = {'original_index': i, 'original_index_in_cache': i} # Simpan original index
        try:
            if ',' in image_base64_str:
                _, image_data_b64_pure = image_base64_str.split(',', 1)
            else:
                image_data_b64_pure = image_base64_str
            
            image_bytes = base64.b64decode(image_data_b64_pure)
            nparr = np.frombuffer(image_bytes, np.uint8)
            img_bgr = cv2.imdecode(nparr, cv2.IMREAD_COLOR)

            if img_bgr is None:
                 app.logger.warning(f"Frame Asli Indeks {i}: Gagal memuat gambar dari base64.")
                 current_analysis_data.update({'quality_ok': False, 'quality_message': "Gagal memuat gambar."})
                 processed_frames_analysis_list.append(current_analysis_data)
                 continue 

            is_quality_good, quality_message = check_image_quality(img_bgr, frame_index=f"LivenessFrame_{i}")
            current_analysis_data['quality_ok'] = is_quality_good
            current_analysis_data['quality_message'] = quality_message
            if not is_quality_good:
                processed_frames_analysis_list.append(current_analysis_data) 
                continue 

            bgr_images_cache[i] = img_bgr # Simpan ke cache dengan original index
            img_rgb = cv2.cvtColor(img_bgr, cv2.COLOR_BGR2RGB)
            face_locations_list = face_recognition.face_locations(img_rgb, model="hog") 
            
            if len(face_locations_list) == 1:
                current_analysis_data['face_found'] = True
                face_landmarks_list_all = face_recognition.face_landmarks(img_rgb, face_locations=face_locations_list)
                if face_landmarks_list_all: 
                    landmarks = face_landmarks_list_all[0]
                    left_eye = landmarks.get('left_eye')
                    right_eye = landmarks.get('right_eye')
                    if left_eye and right_eye:
                        current_analysis_data['left_ear'] = eye_aspect_ratio(left_eye)
                        current_analysis_data['right_ear'] = eye_aspect_ratio(right_eye)
                    else:
                        current_analysis_data['face_found'] = False # Landmarks mata tidak ada
                        current_analysis_data['face_message'] = "Detail mata tidak terdeteksi."
                else:
                    current_analysis_data['face_found'] = False # Landmarks tidak ada
                    current_analysis_data['face_message'] = "Detail wajah tidak terdeteksi."
            elif len(face_locations_list) > 1:
                current_analysis_data['face_found'] = False
                current_analysis_data['face_message'] = f"Terdeteksi lebih dari satu wajah ({len(face_locations_list)})."
            else: 
                current_analysis_data['face_found'] = False
                current_analysis_data['face_message'] = "Tidak ada wajah terdeteksi."
            
            processed_frames_analysis_list.append(current_analysis_data)
        except Exception as e_frame: # Tangkap error lebih umum per frame
            app.logger.error(f"Error saat proses Frame Asli Indeks {i}: {str(e_frame)}", exc_info=True)
            processed_frames_analysis_list.append({'original_index': i, 'quality_ok': False, 'quality_message': f"Error internal proses frame: {str(e_frame)}"})
            continue # Lanjut ke frame berikutnya jika satu frame error

    # Filter hanya frame yang lolos semua pengecekan awal (kualitas OK, 1 wajah, landmarks mata ada)
    valid_frames_for_blink_analysis = [
        f for f in processed_frames_analysis_list 
        if f and f.get('quality_ok') and f.get('face_found') and f.get('left_ear') is not None
    ]

    if not valid_frames_for_blink_analysis:
        first_error_message = "Verifikasi gagal. Pastikan pencahayaan cukup, wajah terlihat jelas, tidak buram, dan hanya ada satu wajah di kamera."
        # Coba cari pesan error yang lebih spesifik dari frame pertama yang gagal
        for analysis_result in processed_frames_analysis_list: # Iterasi semua frame yang diproses
            if analysis_result: # Jika frame diproses (bukan None karena error decode parah)
                if not analysis_result.get('quality_ok') and analysis_result.get('quality_message'):
                    first_error_message = analysis_result.get('quality_message') 
                    break
                if not analysis_result.get('face_found') and analysis_result.get('face_message'):
                    first_error_message = analysis_result.get('face_message')
                    break
        app.logger.warning(f"Liveness GAGAL: Tidak ada frame valid untuk analisis kedipan. Pesan pertama: {first_error_message}")
        return jsonify({"success": False, "message": first_error_message}), 200

    is_live, liveness_message, open_eye_frames_info_sorted = check_blinks(valid_frames_for_blink_analysis)

    if not is_live:
        app.logger.warning(f"Liveness GAGAL setelah check_blinks: {liveness_message}")
        # Tambahkan info jika ada frame yg tidak valid (kualitas/wajah)
        num_initial_frames = len(frames_base64_list)
        num_valid_for_blink = len(valid_frames_for_blink_analysis)
        if num_initial_frames > num_valid_for_blink:
            liveness_message += f" (Beberapa dari {num_initial_frames} frame awal memiliki kualitas kurang baik atau masalah deteksi wajah)."
        return jsonify({"success": False, "message": liveness_message}), 200
    
    app.logger.info(f"Liveness BERHASIL: {liveness_message}")

    # --- Pemilihan dan penyimpanan frame terbaik ---
    if not open_eye_frames_info_sorted: # Jika tidak ada kandidat mata terbuka
        app.logger.warning("Liveness berhasil, TAPI tidak ada frame mata terbuka lebar yang memenuhi EYE_AR_OPEN_THRESH.")
        selected_bgr_image = None
        best_frame_original_index = -1
        # Fallback: Ambil frame pertama dari valid_frames_for_blink_analysis (yang sudah quality OK & face found)
        if valid_frames_for_blink_analysis:
            first_valid_frame_data = valid_frames_for_blink_analysis[0]
            best_frame_original_index = first_valid_frame_data.get('original_index_in_cache', first_valid_frame_data.get('original_index'))
            selected_bgr_image = bgr_images_cache.get(best_frame_original_index)
            if selected_bgr_image is not None:
                app.logger.warning(f"FALLBACK (no open_eye_frames): Menggunakan frame valid pertama (indeks asli {best_frame_original_index}) sebagai foto utama.")
        
        if selected_bgr_image is None: # Jika fallback juga gagal
            app.logger.error("FALLBACK GAGAL TOTAL: Tidak ada frame valid yang bisa diambil dari cache.")
            return jsonify({"success": False, "message": "Verifikasi keaktifan berhasil, namun tidak ada frame wajah yang cukup baik untuk disimpan."}), 200
    else:
        best_frame_info = open_eye_frames_info_sorted[0]
        best_frame_original_index = best_frame_info['original_index'] # Ini adalah original_index_in_cache
        selected_bgr_image = bgr_images_cache.get(best_frame_original_index)

    if selected_bgr_image is None:
        app.logger.error(f"CRITICAL: selected_bgr_image None setelah pemilihan/fallback (indeks {best_frame_original_index}). Cache keys: {list(bgr_images_cache.keys())}")
        return jsonify({"success": False, "message": "Kesalahan internal: Gagal mengambil gambar terbaik."}), 500

    final_img_rgb_check = cv2.cvtColor(selected_bgr_image, cv2.COLOR_BGR2RGB)
    final_face_locations_check = face_recognition.face_locations(final_img_rgb_check, model="hog")
    if len(final_face_locations_check) != 1:
        app.logger.error(f"GAGAL SIMPAN: Frame terbaik (indeks {best_frame_original_index}) memiliki {len(final_face_locations_check)} wajah saat validasi ulang.")
        return jsonify({"success": False, "message": f"Foto terpilih tidak valid (terdeteksi {len(final_face_locations_check)} wajah). Pastikan hanya satu wajah terlihat."}), 200
    
    app.logger.info(f"Validasi ulang OK: Frame terbaik (indeks asli {best_frame_original_index}) dikonfirmasi memiliki 1 wajah dan siap disimpan.")
    
    # ... (sisa kode penyimpanan dan return response tidak berubah)
    best_frame_base64_for_preview = None
    file_extension_for_encode_and_save = ".jpg"
    prefix_for_base64 = "data:image/jpeg;base64,"
    try:
        original_base64_str_for_header = frames_base64_list[best_frame_original_index]
        if ',' in original_base64_str_for_header:
            header_best_frame, _ = original_base64_str_for_header.split(',', 1)
            if header_best_frame and 'image/png' in header_best_frame.lower():
                file_extension_for_encode_and_save = ".png"
                prefix_for_base64 = "data:image/png;base64,"
        encode_param = [cv2.IMWRITE_JPEG_QUALITY, 85] if file_extension_for_encode_and_save == ".jpg" else [cv2.IMWRITE_PNG_COMPRESSION, 3]
        retval, buffer = cv2.imencode(file_extension_for_encode_and_save, selected_bgr_image, encode_param)
        if retval:
            best_frame_base64_for_preview = base64.b64encode(buffer).decode('utf-8')
            best_frame_base64_for_preview = f"{prefix_for_base64}{best_frame_base64_for_preview}"
    except Exception as e_encode_preview:
        app.logger.error(f"Gagal encode frame terbaik ke base64 untuk preview: {str(e_encode_preview)}")
    
    unique_id = uuid.uuid4().hex
    filename = f"main_{safe_username_for_file}_{unique_id[:8]}{file_extension_for_encode_and_save}"
    filepath = os.path.join(UPLOAD_DIR_PYTHON, filename)
    try:
        cv2.imwrite(filepath, selected_bgr_image)
        app.logger.info(f"Foto utama berhasil disimpan sebagai: {filename}")
        liveness_msg_part = liveness_message.split('(')[1].split(')')[0] if '(' in liveness_message and 'kedipan' in liveness_message else f"{MIN_BLINKS_REQUIRED}+ kedipan"
        response_data = { "success": True, "message": f"Verifikasi keaktifan ({liveness_msg_part}) berhasil!", "filename": filename }
        if best_frame_base64_for_preview:
            response_data["best_frame_base64"] = best_frame_base64_for_preview
        return jsonify(response_data), 200
    except Exception as e_save_best:
        app.logger.error(f"Gagal menyimpan file foto utama: {str(e_save_best)}")
        return jsonify({"success": False, "message": f"Gagal menyimpan foto utama terpilih: {str(e_save_best)}"}), 500


# --- Fungsi Deteksi Senyum (Logika Baru: Inner Lip Separation + Mouth Width) ---
def detect_smile_from_landmarks(face_landmarks_list):
    # ... (Fungsi ini tidak berubah dari versi sebelumnya)
    if not face_landmarks_list:
        app.logger.warning("[SMILE_DETECT] Tidak ada face landmarks yang diberikan.")
        return False, "Wajah tidak terdeteksi."
    landmarks = face_landmarks_list[0]
    top_lip = landmarks.get('top_lip', [])
    bottom_lip = landmarks.get('bottom_lip', [])
    if not (top_lip and bottom_lip and len(top_lip) >= 10 and len(bottom_lip) >= 10) : 
        app.logger.warning(f"[SMILE_DETECT] Landmarks bibir tidak lengkap untuk analisis detail. Top: {len(top_lip)}, Bottom: {len(bottom_lip)} poin.")
        return False, "Detail bibir tidak terdeteksi dengan baik."
    app.logger.debug(f"[SMILE_DETECT] Top Lip Landmarks (jumlah {len(top_lip)}): {top_lip}")
    app.logger.debug(f"[SMILE_DETECT] Bottom Lip Landmarks (jumlah {len(bottom_lip)}): {bottom_lip}")
    idx_inner_top_center = 9 if len(top_lip) > 9 else (len(top_lip) - 1) // 2 + 3 
    idx_inner_bottom_center = 9 if len(bottom_lip) > 9 else (len(bottom_lip) - 1) // 2 + 3
    top_inner_lip_y = top_lip[idx_inner_top_center][1]
    bottom_inner_lip_y = bottom_lip[idx_inner_bottom_center][1]
    inner_lip_separation = bottom_inner_lip_y - top_inner_lip_y
    app.logger.debug(f"[SMILE_DETECT] Inner Lip: TopInnerY (idx {idx_inner_top_center})={top_inner_lip_y:.2f}, BottomInnerY (idx {idx_inner_bottom_center})={bottom_inner_lip_y:.2f}, Separation={inner_lip_separation:.2f}")
    MIN_INNER_LIP_SEPARATION_STRICT = 5.0
    teeth_clearly_visible = (inner_lip_separation > MIN_INNER_LIP_SEPARATION_STRICT)
    if not teeth_clearly_visible:
        app.logger.info(f"[SMILE_DETECT] Keputusan: GIGI TIDAK CUKUP TERLIHAT (Separation {inner_lip_separation:.2f} <= Thresh {MIN_INNER_LIP_SEPARATION_STRICT}).")
        return False, f"Mohon buka mulut sedikit lebih lebar agar gigi terlihat jelas (pemisahan bibir saat ini: {inner_lip_separation:.1f}px, butuh > {MIN_INNER_LIP_SEPARATION_STRICT}px)."
    app.logger.info(f"[SMILE_DETECT] Mulut terbuka cukup (Separation {inner_lip_separation:.2f} > {MIN_INNER_LIP_SEPARATION_STRICT}). Lanjut cek lebar mulut.")
    mouth_corner_left = top_lip[0]
    mouth_corner_right = top_lip[6]
    mouth_width = dist.euclidean(mouth_corner_left, mouth_corner_right)
    app.logger.debug(f"[SMILE_DETECT] Mouth Width (outer): {mouth_width:.2f}")
    MIN_MOUTH_WIDTH_FOR_SMILE_WITH_TEETH = 110.0
    mouth_is_wide_enough = (mouth_width > MIN_MOUTH_WIDTH_FOR_SMILE_WITH_TEETH)
    if mouth_is_wide_enough:
        app.logger.info(f"[SMILE_DETECT] Keputusan: SENYUM DENGAN GIGI TERLIHAT Terdeteksi (Separation OK, MouthWidth OK {mouth_width:.2f} > {MIN_MOUTH_WIDTH_FOR_SMILE_WITH_TEETH}).")
        return True, "Senyum dengan gigi terlihat berhasil dideteksi."
    else:
        app.logger.info(f"[SMILE_DETECT] Keputusan: MULUT TERBUKA TAPI KURANG LEBAR (MouthWidth {mouth_width:.2f} <= Thresh {MIN_MOUTH_WIDTH_FOR_SMILE_WITH_TEETH}).")
        return False, f"Mulut sudah terbuka, sekarang coba lebarkan sedikit senyum Anda (lebar mulut saat ini: {mouth_width:.1f}px, butuh > {MIN_MOUTH_WIDTH_FOR_SMILE_WITH_TEETH}px)."

@app.route('/api/capture_smile_photo', methods=['POST'])
def capture_smile_photo_endpoint():
    app.logger.info("Endpoint /api/capture_smile_photo diakses.")
    if 'image_base64' not in request.form or 'username' not in request.form:
        return jsonify({"success": False, "message": "Data input tidak lengkap (membutuhkan gambar dan username)."}), 400
    
    image_base64_str = request.form['image_base64']
    username = request.form['username']
    safe_username_for_file = "".join(c if c.isalnum() else "_" for c in username)
    app.logger.info(f"Menerima permintaan foto senyum untuk username: {username} (safe: {safe_username_for_file})")

    try:
        header_img = None
        if image_base64_str.startswith("data:image"):
            try:
                header_img, image_data_b64_pure = image_base64_str.split(',', 1)
            except ValueError:
                app.logger.error("Format base64 string (smile) tidak sesuai harapan (ada 'data:image' tapi tidak ada koma).")
                return jsonify({"success": False, "message": "Format data gambar senyum tidak valid."}), 400
        else:
            image_data_b64_pure = image_base64_str
            app.logger.debug("Base64 string (smile) dianggap tidak memiliki header data:image.")

        image_bytes = base64.b64decode(image_data_b64_pure)
        nparr = np.frombuffer(image_bytes, np.uint8)
        img_bgr = cv2.imdecode(nparr, cv2.IMREAD_COLOR)

        if img_bgr is None:
            app.logger.error("Gagal memuat gambar senyum dari base64.")
            return jsonify({"success": False, "message": "Gagal memproses gambar yang dikirim."}), 400
        
        is_quality_good, quality_message = check_image_quality(img_bgr, frame_index="smile_capture")
        if not is_quality_good:
            app.logger.warning(f"Kualitas gambar senyum buruk: {quality_message}")
            # Return success:false agar JS bisa menampilkan pesan spesifik ini
            return jsonify({"success": False, "message": quality_message}), 200 

        img_rgb = cv2.cvtColor(img_bgr, cv2.COLOR_BGR2RGB)
        face_locations_list = face_recognition.face_locations(img_rgb, model="hog")

        if len(face_locations_list) == 0:
            return jsonify({"success": False, "message": "Tidak ada wajah yang terdeteksi. Pastikan wajah Anda terlihat jelas."}), 200
        if len(face_locations_list) > 1:
            return jsonify({"success": False, "message": f"Terdeteksi lebih dari satu wajah ({len(face_locations_list)}). Harap pastikan hanya wajah Anda yang ada di kamera."}), 200
        
        face_landmarks_list_all = face_recognition.face_landmarks(img_rgb, face_locations=face_locations_list)
        if not face_landmarks_list_all:
            return jsonify({"success": False, "message": "Tidak dapat mendeteksi detail wajah. Coba lagi dengan pencahayaan lebih baik."}), 200
        
        is_smiling_flag, smile_message = detect_smile_from_landmarks(face_landmarks_list_all)
        
        if not is_smiling_flag:
            return jsonify({"success": False, "message": smile_message}), 200 # Pesan dari detect_smile_from_landmarks
            
        app.logger.info("Senyum (logika baru: separation+width) terdeteksi dan kualitas gambar baik. Menyimpan foto senyum...")
        unique_id = uuid.uuid4().hex
        file_extension = ".jpg"
        if header_img and 'image/png' in header_img.lower(): # Cek header_img yang sudah di-parse
            file_extension = ".png"
        filename_smile = f"smile_teeth_{safe_username_for_file}_{unique_id[:8]}{file_extension}"
        filepath_smile = os.path.join(UPLOAD_DIR_PYTHON, filename_smile)
        try:
            encode_params = [cv2.IMWRITE_JPEG_QUALITY, 85] if file_extension == ".jpg" else [cv2.IMWRITE_PNG_COMPRESSION, 3]
            cv2.imwrite(filepath_smile, img_bgr, encode_params)
            app.logger.info(f"Foto senyum (logika baru) berhasil disimpan sebagai: {filename_smile}")
            return jsonify({
                "success": True,
                "message": "Foto senyum (gigi terlihat) berhasil diverifikasi dan disimpan.",
                "filename": filename_smile
            }), 200
        except Exception as e_save:
            app.logger.error(f"Gagal menyimpan file foto senyum (logika baru): {str(e_save)}")
            return jsonify({"success": False, "message": f"Gagal menyimpan foto senyum: {str(e_save)}"}), 500

    except base64.binascii.Error as e_b64:
        app.logger.error(f"Error decode base64 foto senyum: {str(e_b64)}")
        return jsonify({"success": False, "message": "Data base64 foto senyum tidak valid."}), 400
    except Exception as e_general:
        app.logger.error(f"Error tidak diketahui di endpoint /capture_smile_photo: {str(e_general)}", exc_info=True)
        return jsonify({"success": False, "message": f"Kesalahan internal API saat memproses foto senyum: {str(e_general)}"}), 500

# --- Endpoint untuk Verifikasi Wajah Saat Voting ---
@app.route('/api/verify_face_match', methods=['POST'])
def verify_face_match_endpoint():
    app.logger.info("Endpoint /api/verify_face_match diakses.")
    # ... (Validasi input awal tetap sama)
    if 'image_vote_base64' not in request.form or 'registered_filename' not in request.form:
        return jsonify({"success": False, "match": False, "message": "Data input tidak lengkap."}), 400

    image_vote_base64_str = request.form['image_vote_base64']
    registered_photo_filename = request.form['registered_filename']
    registered_smile_photo_filename = request.form.get('registered_smile_filename', None) 
    current_tolerance = 0.50 

    app.logger.info(f"Menerima permintaan verifikasi. Foto utama: {registered_photo_filename}, Foto senyum: {registered_smile_photo_filename or 'Tidak ada'}")

    image_data_b64_pure = None
    try:
        if image_vote_base64_str.startswith("data:image"):
            try:
                header_part, base64_data_part = image_vote_base64_str.split(',', 1)
                image_data_b64_pure = base64_data_part
            except ValueError:
                app.logger.error("Format base64 string (voting) tidak valid (ada 'data:image' tapi tidak ada koma).")
                return jsonify({"success": False, "match": False, "message": "Format data gambar voting tidak valid."}), 400
        else:
            image_data_b64_pure = image_vote_base64_str
        
        if image_data_b64_pure is None:
            app.logger.error("image_data_b64_pure tidak terdefinisi setelah parsing header (voting).")
            return jsonify({"success": False, "match": False, "message": "Kesalahan internal parsing data gambar voting."}), 500

        image_vote_bytes = base64.b64decode(image_data_b64_pure)
        nparr_vote = np.frombuffer(image_vote_bytes, np.uint8)
        img_vote_bgr = cv2.imdecode(nparr_vote, cv2.IMREAD_COLOR)

        if img_vote_bgr is None:
            app.logger.error("Gagal memuat foto voting dari base64 (img_vote_bgr is None).")
            return jsonify({"success": False, "match": False, "message": "Gagal memproses foto voting (gambar tidak terbaca)."}), 400
        
        # PENTING: check_image_quality untuk foto voting
        is_quality_good_vote, quality_message_vote = check_image_quality(img_vote_bgr, frame_index="vote_image_check")
        if not is_quality_good_vote:
            app.logger.warning(f"Kualitas foto voting buruk: {quality_message_vote}")
            # Kembalikan success:false agar JS bisa menampilkan pesan spesifik ini
            return jsonify({"success": False, "match": False, "message": quality_message_vote}), 200 

        img_vote_rgb = cv2.cvtColor(img_vote_bgr, cv2.COLOR_BGR2RGB)
        vote_face_encodings = face_recognition.face_encodings(img_vote_rgb)
        if not vote_face_encodings:
            return jsonify({"success": True, "match": False, "message": "Tidak dapat menemukan wajah di foto yang Anda ambil untuk voting."}), 200
        vote_encoding = vote_face_encodings[0]

        # --- Verifikasi dengan Foto Utama (Netral) ---
        is_match_main = False # Default
        if registered_photo_filename: # Pastikan ada nama file foto utama
            app.logger.info(f"Mencoba verifikasi dengan foto utama: {registered_photo_filename}")
            safe_reg_fn = os.path.basename(registered_photo_filename)
            path_reg_photo = os.path.join(UPLOAD_DIR_PYTHON, safe_reg_fn)

            if not os.path.exists(path_reg_photo):
                app.logger.error(f"File foto pendaftaran utama tidak ditemukan: {path_reg_photo}")
                # Jangan langsung return error, coba fallback ke senyum jika ada
            else:
                img_reg_rgb = face_recognition.load_image_file(path_reg_photo)
                reg_encodings = face_recognition.face_encodings(img_reg_rgb)
                if not reg_encodings:
                    app.logger.warning(f"Tidak ada wajah di foto utama terdaftar ({safe_reg_fn}).")
                else:
                    reg_encoding_main = reg_encodings[0]
                    distances = face_recognition.face_distance([reg_encoding_main], vote_encoding)
                    dist_val = distances[0] if distances else 1.0
                    app.logger.info(f"[VERIFY_MATCH] Jarak ke foto UTAMA: {dist_val:.4f} (Tolerance: {current_tolerance})")
                    if dist_val <= current_tolerance:
                        is_match_main = True
                        app.logger.info(f"[VERIFY_MATCH] COCOK dengan foto UTAMA.")
                        return jsonify({"success": True, "match": True, "message": "Verifikasi wajah berhasil (cocok dengan foto utama)."}), 200
                    else:
                        app.logger.info(f"[VERIFY_MATCH] Tidak cocok dengan foto UTAMA.")
        else:
            app.logger.warning("Nama file foto utama tidak tersedia untuk verifikasi.")
        
        # --- JIKA GAGAL DENGAN FOTO UTAMA, DAN ADA FOTO SENYUM, COBA DENGAN FOTO SENYUM ---
        if not is_match_main and registered_smile_photo_filename:
            app.logger.info(f"Mencoba verifikasi dengan foto SENYUM: {registered_smile_photo_filename}")
            safe_smile_fn = os.path.basename(registered_smile_photo_filename)
            path_smile_photo = os.path.join(UPLOAD_DIR_PYTHON, safe_smile_fn)

            if not os.path.exists(path_smile_photo):
                app.logger.warning(f"File foto senyum terdaftar tidak ditemukan: {path_smile_photo}")
            else:
                img_smile_rgb = face_recognition.load_image_file(path_smile_photo)
                smile_encodings = face_recognition.face_encodings(img_smile_rgb)
                if not smile_encodings:
                    app.logger.warning(f"Tidak ada wajah di foto SENYUM terdaftar ({safe_smile_fn}).")
                else:
                    reg_encoding_smile = smile_encodings[0]
                    distances_smile = face_recognition.face_distance([reg_encoding_smile], vote_encoding)
                    dist_val_smile = distances_smile[0] if distances_smile else 1.0
                    app.logger.info(f"[VERIFY_MATCH] Jarak ke foto SENYUM: {dist_val_smile:.4f} (Tolerance: {current_tolerance})")
                    if dist_val_smile <= current_tolerance:
                        app.logger.info(f"[VERIFY_MATCH] COCOK dengan foto SENYUM.")
                        return jsonify({"success": True, "match": True, "message": "Verifikasi wajah berhasil (cocok dengan foto senyum)."}), 200
                    else:
                        app.logger.info(f"[VERIFY_MATCH] Tidak cocok dengan foto SENYUM.")
        
        app.logger.info(f"[VERIFY_MATCH] Keputusan Akhir: TIDAK COCOK (setelah semua pengecekan).")
        return jsonify({"success": True, "match": False, "message": "Wajah tidak cocok dengan data pendaftaran Anda."}), 200

    except base64.binascii.Error as e_b64_vote:
        app.logger.error(f"Error decode base64 foto voting: {str(e_b64_vote)}. Data awal: {image_vote_base64_str[:100]}...")
        return jsonify({"success": False, "match": False, "message": "Data base64 foto saat voting tidak valid."}), 400
    except Exception as e_general_vote:
        app.logger.error(f"Error tidak diketahui di endpoint /verify_face_match: {str(e_general_vote)}", exc_info=True)
        return jsonify({"success": False, "match": False, "message": f"Kesalahan internal API verifikasi: {str(e_general_vote)}"}), 500

if __name__ == '__main__':
    app.run(host='localhost', port=5000, debug=True)