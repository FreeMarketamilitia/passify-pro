# ⚠️ **Under Development** ⚠️

## **Seeking Developers!** 💻

We're actively working on **Passify-Pro** and we're looking for talented developers to help make it better. 🚀 If you're interested, jump in by opening a pull request or reach out for collaboration. 🙌

⚠️ **Warning:** The current code may not be stable, so use with caution. We're working on the features, but you're in for a **wild ride**. ⚠️

---

# **Passify-Pro** 🎟️💥

Welcome to **Passify-Pro** – the **plugin that will change your life**. Seriously, who knew event tickets could be so sleek, so secure, and so easy? If **Google Wallet** tickets were a sport, we’d be *undefeated champions*. 🏆⚡

**Passify-Pro** is the most **elegant, revolutionary**, and **indispensable** WordPress plugin to ever exist. We took a long, hard look at what the world needed, and we said, “Why not make Google Wallet tickets as **smooth** as butter on a hot pancake?” 🍯🥞 You’re welcome.

🚨 **PSA:** This is still a **work-in-progress**, but it’s so good you’ll think it’s finished. We’re ironing out the kinks, but you’ll be too busy *freaking out over how awesome it is* to notice. 🚨

---

## **What Does This Thing Do?** 🤔

- **🎟️ Dynamic Pass Creation:** Create event passes for users who purchase specific products in WooCommerce.
- **📦 Product Category-Based Pass Generation:** Admins can select which product categories will trigger pass generation.
- **🔧 Metadata Field Mapping:** Admins can map metadata fields to dynamically build wallet pass classes and objects based on WooCommerce order data.
- **🔐 Decrypted Service Account Key Storage:** The service account key is securely encrypted and only decrypted during API calls.
- **🗓️ Custom Expiration Date:** If no expiration date is provided, passes will have a default expiration date of one week after purchase.
- **🔄 Rotating QR Codes:** Generated passes include rotating QR codes, acting as redemption links.
- **🔒 Secure Redemption Process:** Only authorized roles (e.g., employees) can redeem tickets through a custom redemption API with strict security validation.

---

### **Admin Features** 🛠️

- **⚙️ Admin Settings Page:** Upload Google service account key, select metadata fields, and define which product categories trigger pass generation.
- **📜 Log Viewing:** Admins can view logs detailing the creation and management of passes.
- **📝 Metadata Field Configuration:** Select and create metadata fields for dynamic Google Wallet pass building.

---

## **Installation** 🛠️

1. **🔽 Download Passify-Pro:**
   - **Click that download button**. Don’t be shy. 😏

2. **📤 Install It Like a Pro:**
   - Upload the **.zip file** through **Plugins > Add New**. Easy, right? 👌

3. **⚡ Activate and Get Ready to Be Amazed:**
   - Go to **Plugins > Installed Plugins** and click **Activate**. **Boom** – you’re ready. 🚀

4. **🎶 Run Composer (If You’re Fancy):**
   - Composer ensures everything works like magic. Run:
   ----
   ```bash
   	composer install
   ```

   ---

## **Configure Passify-Pro** ⚙️

1. **Upload Google Service Account Key**:
   - In the **Admin Settings** page, you'll be able to upload your Google service account key. This key is securely encrypted and used for authenticating with Google Wallet.

2. **Set Metadata Fields**:
   - Define the metadata fields that will dynamically create Google Wallet passes. Map them based on WooCommerce order data.

3. **Configure Product Categories**:
   - Select which WooCommerce product categories will trigger the creation of Google Wallet passes when a purchase is made.

4. **View Logs**:
   - Admins can view detailed logs of pass creation, management, and other activities related to Passify-Pro.

---

## **Usage** 💡

Once the plugin is activated, users who purchase specific products from the selected categories will automatically receive a Google Wallet pass. The pass will include:

- **Dynamic Metadata**: Custom metadata related to the purchase.
- **Expiration Date**: Default expiration of one week, or as set in the product metadata.
- **Rotating QR Code**: A QR code that links to your custom redemption API.

---

## **Custom Redemption API** 🔒

The **redemption API** allows only authorized roles (e.g., employees) to redeem tickets using the generated passes. The redemption process is secured with strict validation to ensure the authenticity of each ticket.

To set up your redemption API:

1. Ensure you have the correct roles configured.
2. Define a secure endpoint for ticket redemption.
3. Implement the necessary checks to verify the pass is valid and hasn't expired.

---

## **Contribute** 🤝

### We ❤️ Contributions!

If you’d like to contribute, here’s how you can get started:

1. **Fork the repository**.
2. **Create a new branch** for your feature or fix.
3. **Make your changes** and write tests.
4. **Submit a pull request** – we’ll review it and merge it into the main branch if everything checks out.

---

## **Contact** 📬

If you have any questions or need help, feel free to reach out! We’re always happy to assist. You can contact us at:

- **GitHub Discussions**: [link to discussions page](https://github.com/freemarketamilita/passify-pro/discussions)

---

## **License** 📜

This project is licensed under the **MIT License**. See the [LICENSE](LICENSE) file for more information.

---

## **Acknowledgements** 🙏

A big thank you to all the open-source contributors and libraries that made this project possible. We couldn’t have done it without your amazing work! 💥
