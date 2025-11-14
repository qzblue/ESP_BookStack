# ESP Maintenance Theme

此主題為 BookStack v25.11.1 提供「文章維護與審核系統」，包含以下內容：

- Logical Theme：註冊維護任務路由、控制器、服務層與 Artisan 指令。
- Visual Theme：覆寫頁面詳情、頁首及維護任務列表介面，讓維護資訊與操作出現在 UI 中。
- Artisan 指令：
  - `esp:migrate-maintenance`：建立 `page_maintenances` 資料表。
  - `esp:maintenance-check`：每日巡檢維護狀態並寄送提醒。

## 安裝與啟用

1. 將 `themes/esp` 放入 BookStack 專案的 `themes/` 目錄中。
2. 在 `.env` 或後台系統設定中把 `APP_THEME` 設為 `esp`，確保 Logical Theme 與 Visual Theme 一併載入。
3. 於伺服器上執行一次資料表建立指令：

   ```bash
   php artisan esp:migrate-maintenance
   ```

   指令具冪等性，重複執行不會重建資料表。

4. 透過 crontab 排程每日巡檢，例如：

   ```cron
   0 4 * * * php /path/to/bookstack/artisan esp:maintenance-check
   ```

   指令會依 `next_due_at` 自動更新狀態並寄送 email/站內通知。

## 使用說明

- 系統管理員可在 Page 詳情頁右側的「維護」卡片中指派維護人與週期，並在任務列表頁 `/maintenance/tasks` 審核待審頁面。
- 文章維護人員會在到期前後收到提醒，並可於 Page 詳情頁啟動更新或提交審核。
- 一般讀者僅能瀏覽已審核通過的內容，維護流程在 theme 層控制，不需修改核心程式。

如需調整「到期前幾天提醒」的門檻，可在 `MaintenanceService::DUE_SOON_THRESHOLD_DAYS` 常數中修改。
