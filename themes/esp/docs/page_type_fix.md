# 維護資料表 `page_type` 欄位修復指引

若前台或任務列表出現以下 SQL 錯誤：

- `Unknown column 'page_type' in 'WHERE'`
- `Column 'page_id' cannot be null`

請依序執行下列步驟修復：

1. 以 BookStack 專案根目錄為當前工作目錄，執行資料表修補指令：

   ```bash
   php artisan esp:migrate-maintenance
   ```

   - 指令會在缺少 `page_type` 欄位時自動補齊並填入預設值。
   - 若主表尚未建立，會直接建立正確的表結構與外鍵設定。

2. 清除快取，確保最新設定與視圖被載入：

   ```bash
   php artisan cache:clear
   php artisan view:clear
   ```

3. 重新整理頁面；若仍有問題，請確認目前登入帳號具備系統管理權限並重新執行第 1 步。

此文件位於 `themes/esp/docs/page_type_fix.md`，方便日後維運時快速查閱。
