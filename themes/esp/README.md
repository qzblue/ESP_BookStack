# ESP Maintenance Theme

此主題為 BookStack v25.11.1 提供「文章維護與審核系統」，包含以下內容：

- **Logical Theme**：註冊維護任務路由、控制器、服務層與 Artisan 指令。
- **Visual Theme**：覆寫頁面詳情、頁首及維護任務列表介面，讓維護資訊與操作出現在 UI 中。
- **Artisan 指令**：
  - `esp:migrate-maintenance`：建立 `page_maintenances` 資料表。
  - `esp:maintenance-check`：每日巡檢維護狀態並寄送提醒。

---

## 安裝與啟用

1. 將 `themes/esp` 放入 BookStack 專案的 `themes/` 目錄中。
2. 在 `.env` 或後台系統設定中把 `APP_THEME` 設為 `esp`，並確認 `APP_TIMEZONE=Asia/Shanghai`（以東八區時間排程及寄送提醒），確保 Logical Theme 與 Visual Theme 一併載入。
3. 建立維護資料表（僅需執行一次）：

   ```bash
   php artisan esp:migrate-maintenance
   ```

   > 指令具冪等性，重複執行不會重建資料表。

4. 若先前已快取設定或路由，請先清除快取後再重新載入頁面：

   ```bash
   php artisan cache:clear
   php artisan config:clear
   php artisan route:clear
   php artisan view:clear
   ```

---

## 介面入口與操作流程

| 功能 | 入口 | 說明 |
| ---- | ---- | ---- |
| 維護任務總覽 | `https://<your-domain>/maintenance/tasks` | 任何已登入的使用者都可開啟。頁面上方顯示「我負責的頁面」，具管理權限者（Admin）還會看到「待審頁面」。 |
| 頁首維護圖示 | 右上角導覽列的日曆圖示 | 會顯示待處理數量，點擊會跳轉到 `/maintenance/tasks`。 |
| Page 詳情維護卡片 | 任一 Page 右側欄「維護」卡片 | Admin 可在此指派維護人與週期、執行審核；指定的 Maintainer 可啟動更新或提交審核。一般讀者在審核期間只會看到最近一次審核通過的版本。 |
| 編輯/修訂聯動 | 編輯並儲存 Page | 維護人編輯後會自動切換為「審核中」並通知管理員；管理員直接編輯則會視為通過並重算下一次到期。 |

> **提示**：要讓頁首圖示與維護卡片顯示資訊，必須先在某個 Page 完成一次「指派維護人」。

### 與編輯 / 修訂聯動

- **維護人編輯頁面**：只要維護人對 Page 進行編輯並儲存，系統會自動把維護狀態切換為「待審」，並通知管理員審核該修訂版本（可於內建修訂歷史檢視差異）。
- **管理員編輯頁面**：若管理員直接編輯並儲存，系統會把狀態標記為最新版本並重新計算下一次到期日，免除額外審核步驟。
- **提醒依據**：到期檢查與通知依 `next_due_at` 計算，並使用 Page 的修訂紀錄作為內容變更依據，無須額外上傳。
- **讀者可見版本**：當維護狀態為「審核中」時，非管理員且非維護人僅會看到最後一次審核通過（或最後一次紀錄的）修訂版本，避免未審內容直接曝光。

---

## 功能驗證步驟

1. 以 Admin 身分開啟任一 Page（例如 `https://<your-domain>/books/<book-slug>/page/<page-slug>`）。
2. 在右側的「維護」卡片中：
   - 從下拉選單選擇維護人（BookStack 使用者）。
- 設定維護週期：最小單位為「分鐘」，可自由組合「天 / 小時 / 分鐘」；至少填入 1 分鐘即可啟用週期。
   - 儲存後會建立 `page_maintenances` 紀錄並顯示目前狀態與下一次到期日。
3. 以被指派的維護人登入同一 Page：
   - 當狀態為「即將到期」或「已逾期」時，可按下「開始更新」→ 修改內容 → 「提交審核」。
4. Admin 可透過：
   - 頁首日曆圖示 → `/maintenance/tasks` → 「待審頁面」區塊審核。
   - 或直接回到 Page 右側卡片使用「審核通過 / 駁回」。
5. 駁回時需輸入理由；通過後系統會重設下一次到期日並通知維護人。

---

## 排程與提醒

- 建議在伺服器 crontab 新增每日巡檢（伺服器時區建議設為 Asia/Shanghai，或在 crontab 內加入 `TZ=Asia/Shanghai`）：

  ```cron
  0 4 * * * php /path/to/bookstack/artisan esp:maintenance-check
  ```

- 指令會依 `next_due_at` 自動更新狀態並寄送 email/站內通知。
- 「即將到期」的門檻預設為 7 天，可視需求調整 `MaintenanceService::DUE_SOON_THRESHOLD_DAYS` 常數。

### 分鐘級快速驗證

1. 在 Page 右側「維護」卡片設定天/小時/分鐘，使總時長至少 1 分鐘（最小單位為分鐘）。
2. 確認 `.env` 的 `APP_TIMEZONE=Asia/Shanghai` 或 crontab 已設定 `TZ=Asia/Shanghai`。
3. 手動執行每日巡檢以觸發狀態變更與通知：

   ```bash
   php artisan esp:maintenance-check
   ```

4. 重新整理 Page 詳情或 `/maintenance/tasks`，狀態應變為「即將到期」或「已逾期」，並收到郵件/站內通知。

---

## 常見問題

- **看不到維護卡片或任務列表**：
  - 確認目前登入帳號具有系統管理權限（或已被指派為維護人）。
  - 重新清除快取並重新載入頁面：

    ```bash
    php artisan cache:clear
    php artisan view:clear
    ```

  - 確保已至少在一個 Page 完成指派，否則系統沒有維護紀錄可顯示。

- **指令找不到**：
  - 確保 theme 已啟用且 `themes/esp/functions.php` 可被載入。
  - 執行 Artisan 時需在 BookStack 專案根目錄下並載入 `vendor/autoload.php`。

- **Email 沒收到**：
  - 檢查 BookStack 的 mail 設定是否正確。
  - 確認 `.env` 的時區為 `Asia/Shanghai`，並已指派維護週期；系統會在達到 `next_due_at` 時變更狀態並觸發提醒。
  - 可先使用 `php artisan tinker` 測試寄信或檢查佇列服務。

- **出現 `page_type` 欄位不存在或相關 SQL 錯誤**：
  - 早期版本的維護資料表沒有 `page_type` 欄位，請在 BookStack 專案根目錄重新執行：

    ```bash
    php artisan esp:migrate-maintenance
    ```

  - 指令會自動補齊欄位並填入預設值，無須手動修改資料庫。
  - 也可參考 `themes/esp/docs/page_type_fix.md` 取得完整修復步驟。

如需進一步調整，可修改 `themes/esp/logic` 內的服務、命令或通知邏輯。
