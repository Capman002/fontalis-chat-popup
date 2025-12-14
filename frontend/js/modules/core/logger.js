class FontalisFrontendLogger {
  constructor(isEnabled) {
    this.isEnabled = isEnabled;
  }

  log(...args) {
    if (this.isEnabled) {
      console.log(...args);
    }
  }

  warn(...args) {
    if (this.isEnabled) {
      console.warn(...args);
    }
  }

  error(...args) {
    if (this.isEnabled) {
      console.error(...args);
    }
  }

  info(...args) {
    if (this.isEnabled) {
      console.info(...args);
    }
  }

  debug(...args) {
    if (this.isEnabled) {
      console.debug(...args);
    }
  }
}

// Instanciar o logger globalmente, assumindo que fontalisPluginSettings estará disponível
// e terá a propriedade frontendLoggingEnabled
const FontalisLogger = new FontalisFrontendLogger(
  false, // Logging desabilitado para segurança em produção
);

export { FontalisLogger, FontalisFrontendLogger };
