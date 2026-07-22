# Đóng băng site & bỏ proxy (freeze snapshot)

Ngày: 2026-07-23
Theme: `wp-content/themes/vitech-clone`

## Mục tiêu

Loại bỏ cơ chế **proxy trực tiếp** (live-fetch HTML + assets từ `vitechlift.com`) khỏi
theme, nhưng **giữ y nguyên giao diện và cấu trúc** hiện tại. Sau khi làm xong:

- Site hiển thị **hệt như bây giờ**.
- Runtime **không còn gọi mạng ra `vitechlift.com`** (cả HTML lẫn CSS/JS/ảnh/font).
- **Nội dung động vẫn sống**: sản phẩm/giá (WooCommerce), giỏ hàng localStorage,
  tin tức, form, menu... vẫn do các injector hiện có dựng ở runtime.

## Nguyên tắc cốt lõi

Tách **"cái vỏ tĩnh"** (đóng băng một lần) khỏi **"nội dung động"** (dựng mỗi request):

- Cái vỏ = HTML nguồn thô của một số **template mẫu** + toàn bộ assets Flatsome.
- Nội dung động = mọi thứ các hàm `vitech_clone_inject_*` / `render_*` chèn vào cái vỏ.

Chỉ **phần fetch mạng** bị gỡ. Toàn bộ logic render/inject/recolor/rebrand giữ nguyên.

## Kiến trúc hiện tại (điểm xuất phát)

- Mọi template stub (`front-page.php`, `page.php`, `single.php`, `404.php`) →
  `require proxy.php`.
- `functions.php::vitech_clone_proxy_public_pages()` (hook `template_redirect`) là cửa vào:
  `?vitech_asset=` → `asset-proxy.php`; còn lại → `proxy.php`.
- `proxy.php` (2399 dòng):
  1. Map route local → 1 **source URL mẫu** (chi tiết SP → `/may-keo-fj100a/`,
     danh mục → `/may-keo-thang-may/`, bài viết → `/may-keo-fjt-new-version/`,
     tin → `/tin-tuc-su-kien/`, trang tĩnh → path của chính nó, tìm kiếm → `/?s=`).
  2. `wp_remote_get(source_url)` + cache transient 15' → **network #1**.
  3. Rewrite URL assets `wp-content|wp-includes` → `?vitech_asset=<encoded>` (closure `$asset_url`).
  4. Rewrite link trang `vitechlift.com` → host local.
  5. Chuỗi injector nội dung động + recolor xanh→ghi + rebrand VITECH→VILIFT.
  6. `echo`.
- `asset-proxy.php`: mỗi asset `?vitech_asset=` → `wp_remote_get` từ nguồn (**network #2**),
  CSS đổi `#159158/#0B9344`→`#6b7280` + rewrite `url()` đệ quy, PNG ngả xanh → GD grayscale.

## Thiết kế đích

### 1. Snapshot HTML (cái vỏ)

- Chụp **HTML nguồn thô** (đúng bytes `wp_remote_get` trả về, **trước** mọi rewrite/inject)
  cho từng source URL mẫu — tập hữu hạn ~13 key, không phải 92 sản phẩm:

  | key            | source path                 | dùng cho |
  |----------------|-----------------------------|----------|
  | `home`         | `/`                         | trang chủ / index |
  | `product`      | `/may-keo-fj100a/`          | mọi `single product` |
  | `product_cat`  | `/may-keo-thang-may/`       | mọi `product_cat` |
  | `post`         | `/may-keo-fjt-new-version/` | mọi `single post` |
  | `news`         | `/tin-tuc-su-kien/`         | page `tin-tuc` |
  | `search`       | `/?s=thang+may`             | mọi tìm kiếm |
  | `page-gioi-thieu` … | `/gioi-thieu/` …       | từng trang tĩnh (gioi-thieu, lien-he, tai-lieu, san-pham, yeu-cau-bao-gia, gio-hang) |

- Lưu: `themes/vitech-clone/snapshots/<key>.html`.
- **Chụp thô, không phải bản render** → injector vẫn chạy runtime → thêm/sửa sản phẩm,
  giá, tin trong wp-admin vẫn phản ánh ngay.

### 2. Đóng băng assets

- Xác định **chính xác tập asset site local thực sự dùng**: crawl HTML đã render của
  từng route trên site local (`https://thang-may.ddev.site/...`), gom mọi URL `?vitech_asset=<src>`.
  Đây đúng bằng tập asset trình duyệt sẽ tải → tối thiểu, không thừa.
- Với mỗi asset: tải **bản đã xử lý** (CSS đã recolor + PNG đã grayscale) — tái dùng đúng
  logic `asset-proxy.php` (copy vào tool build), lưu vào `themes/vitech-clone/frozen/**`
  mirror path nguồn (vd `frozen/wp-content/uploads/2024/x.png`).
- CSS: rewrite `url()/@import` bên trong → path `frozen/` local; đệ quy tải font/ảnh nó trỏ tới.
- Assets external **không phải vitechlift** (Google reCAPTCHA, Google Fonts nếu có) **giữ nguyên** —
  không thuộc "proxy". Botpress vẫn bị gỡ như hiện tại.

### 3. Đổi `proxy.php` → `render.php`

Đổi tên file cho đúng bản chất (engine render local, không còn proxy). Cập nhật 5 template
stub + `functions.php` trỏ tới `render.php`. Nội dung file đổi **đúng 2 chỗ**, phần còn
lại giữ nguyên:

- **Bỏ khối fetch** (`wp_remote_get` + transient): thay bằng đọc `snapshots/<key>.html`
  theo key map từ route. Runtime **không gọi mạng**.
  - Nếu thiếu snapshot cho route → **báo lỗi tĩnh** (`status_header(500)` + trang thông báo
    ngắn), **tuyệt đối không fallback ra mạng**.
- **Closure `$asset_url`**: trả về `get_template_directory_uri() . '/frozen' . <path>`
  thay vì `?vitech_asset=`. Toàn bộ regex rewrite giữ nguyên, chỉ đổi đích.

Việc map "route → snapshot key" tách thành 1 hàm thuần `vitech_clone_snapshot_key()` dùng lại
đúng các điều kiện `is_singular('product')` / `is_tax('product_cat')` / `is_singular('post')` /
`is_page(...)` / search đang có ở đầu file.

### 4. Dọn dẹp & tooling

- Xóa `asset-proxy.php` và nhánh `if (isset($_GET['vitech_asset']))` trong `functions.php`
  (frozen HTML không còn tham chiếu `?vitech_asset=`).
- Thêm `themes/vitech-clone/tools/freeze.php` — chạy bằng `ddev wp eval-file`:
  1. Fetch HTML thô mỗi source URL mẫu → ghi `snapshots/`.
  2. Crawl route local, gom `?vitech_asset=`, tải + xử lý (recolor/grayscale/rewrite CSS
     đệ quy) → ghi `frozen/`.
  Đây là **nơi duy nhất còn code gọi mạng** trong theme; chỉ chạy khi cần tái tạo, không
  nằm trong đường request.

## Luồng dữ liệu sau khi làm

```
Request  →  render.php
             ├─ snapshot_key(route)  →  đọc snapshots/<key>.html   (đĩa, không mạng)
             ├─ rewrite $asset_url   →  /frozen/...                (đĩa, không mạng)
             ├─ inject_* / render_*  →  dữ liệu WP local (sản phẩm, giỏ, tin, form, menu)
             ├─ recolor xanh→ghi + rebrand VILIFT
             └─ echo
```

## Xử lý lỗi

- Thiếu snapshot → trang lỗi tĩnh 500, không gọi mạng.
- Asset frozen thiếu → nginx trả 404 tĩnh (không PHP, không mạng).
- Tool `freeze.php` lỗi mạng khi build → log rõ URL hỏng, tiếp tục các URL khác.

## Kiểm thử / nghiệm thu

1. Sau build, `grep -r "vitechlift.com" themes/vitech-clone --include=*.php` chỉ còn xuất hiện
   trong `tools/freeze.php` (và có thể vài chuỗi rebrand cố ý), **không** ở đường runtime.
2. Chặn mạng ra ngoài (hoặc tắt): duyệt trang chủ, 1 sản phẩm, 1 danh mục, 1 bài viết, trang
   tin, tìm kiếm, giỏ hàng, các trang tĩnh → **render đúng như trước**.
3. DevTools Network: **0 request tới `vitechlift.com`**; không có 404 asset.
4. Nội dung động: thêm 1 sản phẩm trong wp-admin → xuất hiện ở danh mục/trang chủ (injector
   còn sống). Thêm vào giỏ → count header + trang `/gio-hang/` hoạt động.
5. So sánh mắt thường trước/sau: giao diện, màu ghi, brand VILIFT không đổi.

## Ngoài phạm vi (YAGNI)

- Không viết lại thành theme Flatsome native.
- Không localize font/script external không thuộc vitechlift (Google, reCAPTCHA).
- Không tối ưu/minify assets thêm; giữ đúng bytes đã xử lý.

## Rủi ro & giảm thiểu

- **Asset nạp bằng JS** (không xuất hiện dạng `?vitech_asset=` tĩnh) có thể sót → bước
  kiểm thử #3 soi Network để phát hiện & bổ sung vào danh sách crawl.
- **Kích thước repo tăng** vì commit `frozen/**` (vài MB CSS/JS/font/ảnh) — chấp nhận, đổi lấy
  độc lập hoàn toàn.
- **Nguồn đổi sau này** không còn ảnh hưởng (đã đóng băng) — đúng mục tiêu; muốn cập nhật thì
  chạy lại `tools/freeze.php`.
