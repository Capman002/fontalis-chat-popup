import { FontalisLogger } from "../core/logger.js";

class AjaxService {
  constructor(ajaxUrl) {
    this.ajaxUrl = ajaxUrl;
  }

  async post(action, data) {
    const formData = new FormData();
    formData.append("action", action);
    for (const key in data) {
      formData.append(key, data[key]);
    }

    try {
      const response = await fetch(this.ajaxUrl, {
        method: "POST",
        body: formData,
      });

      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }

      return await response.json();
    } catch (error) {
      FontalisLogger.error("AjaxService Error:", error);
      throw error;
    }
  }
}

export { AjaxService };
