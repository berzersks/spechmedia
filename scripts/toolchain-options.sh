# Resolve installer names to TypePHP modules and php-src configure switches.
resolve_toolchain_options() {
  runtime=(swoole posix)
  configure_flags=()
  zts=off
  local selection=" $* " extension
  if [[ "$selection" == *' swow '* ]]; then
    if [[ "$selection" == *' swoole '* || "$selection" == *' swoole-hook-'* ]]; then
      echo 'Swow é incompatível com Swoole e seus hooks. Escolha um dos dois.' >&2
      return 2
    fi
    runtime=(posix)
  fi
  for extension do
    case "$extension" in
      swoole|posix|xdebug) ;;
      parallel) runtime+=(parallel); zts=on ;;
      swoole-hook-mysql) runtime+=(pdo pdo_mysql mysqlnd); configure_flags+=(--enable-mysqlnd) ;;
      swoole-hook-pgsql) runtime+=(pdo pgsql); configure_flags+=(--enable-swoole-pgsql) ;;
      swoole-hook-sqlite) runtime+=(pdo sqlite3); configure_flags+=(--enable-swoole-sqlite) ;;
      pdo_pgsql) [[ "$selection" == *' swoole-hook-pgsql '* ]] || runtime+=(pdo_pgsql) ;;
      pdo_sqlite) [[ "$selection" == *' swoole-hook-sqlite '* ]] || runtime+=(pdo_sqlite) ;;
      mbregex) runtime+=(mbstring); configure_flags+=(--enable-mbregex) ;;
      password-argon2) configure_flags+=(--with-password-argon2) ;;
      odbc) runtime+=(odbc); configure_flags+=(--with-unixODBC) ;;
      pdo_odbc) runtime+=(pdo_odbc pdo); configure_flags+=(--with-pdo-odbc=unixODBC) ;;
      imap) runtime+=(imap); configure_flags+=(--with-imap-ssl --with-kerberos) ;;
      event) runtime+=(event sockets openssl); configure_flags+=(--with-event-core --with-event-extra) ;;
      snappy) runtime+=(snappy); configure_flags+=(--with-snappy-includedir=/usr) ;;
      memcached) runtime+=(memcached session zlib) ;;
      uv) runtime+=(uv sockets) ;;
      pdo_sqlsrv) runtime+=(pdo_sqlsrv pdo) ;;
      swow) runtime+=(swow tokenizer); configure_flags+=(--disable-swow-pdo-pgsql) ;;
      *) runtime+=("$extension") ;;
    esac
  done
}
