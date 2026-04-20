const userEmailLabel = document.querySelector("[data-user-email]");
const logoutButton = document.querySelector("[data-logout]");

async function loadSession() {
  try {
    const response = await window.apiClient.get("api/session.php");
    const user = response.data || {};

    if (userEmailLabel) {
      userEmailLabel.textContent = user.email || "admin@avaliiesst.com";
    }
  } catch (error) {
    window.location.href = "index.html";
  }
}

loadSession();

if (logoutButton) {
  logoutButton.addEventListener("click", async () => {
    try {
      await window.apiClient.delete("api/session.php");
    } catch (error) {
      // Ignore and force redirect.
    }

    window.location.href = "index.html";
  });
}
