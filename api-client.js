(() => {
  async function request(url, options = {}) {
    if (window.location.protocol === "file:") {
      throw new Error("Abra o projeto por um servidor PHP, por exemplo: http://localhost/... e nao clicando direto no index.html.");
    }

    const settings = {
      method: options.method || "GET",
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
        ...(options.headers || {}),
      },
    };

    if (options.body !== undefined) {
      settings.headers["Content-Type"] = "application/json";
      settings.body = JSON.stringify(options.body);
    }

    let response;

    try {
      response = await fetch(url, settings);
    } catch (error) {
      throw new Error(
        "Nao foi possivel conectar ao backend PHP. Verifique se o servidor local esta rodando e se voce abriu o projeto por http://localhost.",
      );
    }

    const responseText = await response.text();

    let payload = null;

    if (responseText) {
      try {
        payload = JSON.parse(responseText);
      } catch (error) {
        if (responseText.trim().startsWith("<")) {
          throw new Error(
            "O servidor retornou HTML em vez de JSON. Isso normalmente acontece quando o PHP nao esta sendo executado pelo servidor.",
          );
        }

        throw new Error("Resposta invalida do servidor.");
      }
    }

    if (!response.ok) {
      throw new Error(
        payload?.message ||
          "O backend PHP respondeu com erro. Verifique se o servidor PHP esta ativo, se o MySQL esta configurado e se o banco foi importado.",
      );
    }

    return payload || { success: true };
  }

  window.apiClient = {
    request,
    get(url) {
      return request(url);
    },
    post(url, body) {
      return request(url, { method: "POST", body });
    },
    put(url, body) {
      return request(url, { method: "PUT", body });
    },
    delete(url) {
      return request(url, { method: "DELETE" });
    },
  };
})();
