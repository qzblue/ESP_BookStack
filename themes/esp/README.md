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
2. 在 `.env` 或後台系統設定中把 `APP_THEME` 設為 `esp`，確保 Logical Theme 與 Visual Theme 一併載入。
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
| Page 詳情維護卡片 | 任一 Page 右側欄「維護」卡片 | Admin 可在此指派維護人與週期、執行審核；指定的 Maintainer 可啟動更新或提交審核。 |

> **提示**：要讓頁首圖示與維護卡片顯示資訊，必須先在某個 Page 完成一次「指派維護人」。

---

## 功能驗證步驟

1. 以 Admin 身分開啟任一 Page（例如 `https://<your-domain>/books/<book-slug>/page/<page-slug>`）。
2. 在右側的「維護」卡片中：
   - 從下拉選單選擇維護人（BookStack 使用者）。
   - 設定維護週期（天）。
   - 儲存後會建立 `page_maintenances` 紀錄並顯示目前狀態與下一次到期日。
3. 以被指派的維護人登入同一 Page：
   - 當狀態為「即將到期」或「已逾期」時，可按下「開始更新」→ 修改內容 → 「提交審核」。
4. Admin 可透過：
   - 頁首日曆圖示 → `/maintenance/tasks` → 「待審頁面」區塊審核。
   - 或直接回到 Page 右側卡片使用「審核通過 / 駁回」。
5. 駁回時需輸入理由；通過後系統會重設下一次到期日並通知維護人。

---

## 排程與提醒

- 建議在伺服器 crontab 新增每日巡檢：

  ```cron
  0 4 * * * php /path/to/bookstack/artisan esp:maintenance-check
  ```

- 指令會依 `next_due_at` 自動更新狀態並寄送 email/站內通知。
- 「即將到期」的門檻預設為 7 天，可視需求調整 `MaintenanceService::DUE_SOON_THRESHOLD_DAYS` 常數。

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
  - 可先使用 `php artisan tinker` 測試寄信或檢查佇列服務。

- **出現 `page_type` 欄位不存在或相關 SQL 錯誤**：
  - 早期版本的維護資料表沒有 `page_type` 欄位，請在 BookStack 專案根目錄重新執行：

    ```bash
    php artisan esp:migrate-maintenance
    ```

  - 指令會自動補齊欄位並填入預設值，無須手動修改資料庫。

如需進一步調整，可修改 `themes/esp/logic` 內的服務、命令或通知邏輯。
