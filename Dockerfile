# 使用一個官方的、乾淨的 PHP 8.2 環境作為基礎
FROM php:8.2-cli

# 安裝 PHP 運作所需要的系統工具和擴充功能函式庫
RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip

# 手動安裝 PHP 的核心擴充功能
RUN docker-php-ext-install zip bcmath sockets

# 安裝 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 設定我們在容器內的工作目錄
WORKDIR /app

# 將我們專案的所有檔案複製到容器的工作目錄中
COPY . .

# 執行 Composer 安裝指令來下載套件
RUN composer require php-binance-api/php-binance-api

# 設定當容器啟動時，要執行的預設指令
CMD [ "php", "analyze_futures.php" ]
