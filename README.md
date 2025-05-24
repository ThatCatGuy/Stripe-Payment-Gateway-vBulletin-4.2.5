# Stripe-Payment-Gateway-vBulletin-4.2.5
A fully working vBulletin 4.2.5 Stripe Payment API made from the vBulletin 5 class_stripe.php.
![1ac862f7c8f1a3c91a17eb66e55f748d609f653c4d2bf43d9b12adef8568555c](https://github.com/user-attachments/assets/d67a4ea7-fd9d-469f-80f4-5379fac0fd26)

# Information:
This was made to be used on **vBulletin 4.2.5** it might work on lower version but thats for others to test.
# Installing It:
1. Go to your forums, open the **AdminCP** and login.
2. On the left menu find **Plugins & Products** and expand it.
3. Click on **Manage Products** and at the bottom of the page click on **[Add/Import Product]**
4. Then click on the **Browse** button and then find the **product-stripecheckout_api.xml** file located inside the **StripeAPI Product/xml/** folder select it and click on **Import**. 5. This will import the Payment API, settings and the Phrases that are required for the API to work.
6. Now you need to set it up.
7. On the left menu find **Paid Subscriptions** and expand it.
8. Click on **Payment API Manager** and you will see **Stripe Checkout** click on the **Edit** button to the right.
It will look like this
![Screenshot 2025-05-23 231655](https://github.com/user-attachments/assets/4f258174-f687-423c-8a1c-12d793304a11)
9. Now you need to fill the input fields out this is explained below them.
10. The two urls at the bottom are Required and you can set what ever you want here if you have a custom webpage to show them after payment or if its cancelled you can set it here.
# Set up Subscription
1. You need to set one up to use Stripe or any payment gateway.
2. Go to your forums, open the **AdminCP** and login.
3. On the left menu find **Paid Subscriptions** and expand it.
4. Click on **Subscription Manager** and then click on **Add New Subscription**

This is my test one.
![Screenshot 2025-05-23 232411](https://github.com/user-attachments/assets/e39113c7-6e52-4429-b06c-2bfb22f82e47)
# Upgrade
1. Go to **http://www.example.com/payments.php** and find the Subscription you want to pay for.

![Screenshot 2025-05-23 220046](https://github.com/user-attachments/assets/d225c81e-8848-426e-bef9-ac5d9fc529d6)

2. Then click on **Order** and it will take you to the pay where you choose which Gateway to pay with.

![Screenshot 2025-05-23 221446](https://github.com/user-attachments/assets/849f547b-7ab9-49a3-b9c4-5c736702adc1)

# Single Payment
![Screenshot 2025-05-23 221511](https://github.com/user-attachments/assets/9188a7c6-421d-4028-9ae8-f48f23ffb90f)
# Recurring Payment
![Screenshot 2025-05-23 222217](https://github.com/user-attachments/assets/d333bbce-fd0c-46d2-9c2c-1812779c4c96)

# Payments Page Active Subscription 
![Screenshot 2025-05-23 222004](https://github.com/user-attachments/assets/d43efcd9-2374-4079-94b3-d89fc2e4d59d)

# Paid Subscription Logs AdminCP
![Screenshot 2025-05-23 222026](https://github.com/user-attachments/assets/6d874cf6-c9f0-4537-a0e9-5d7efb73aff4)
